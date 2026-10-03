import 'dart:io';

import 'package:image/image.dart' as img;

void main() {
  final sourceFile = File('assets/icon/app_icon_source.png');
  if (!sourceFile.existsSync()) {
    stderr.writeln('Source image not found!');
    exit(1);
  }

  final bytes = sourceFile.readAsBytesSync();
  final decoded = img.decodeImage(bytes);
  if (decoded == null) {
    stderr.writeln('Failed to decode source image!');
    exit(1);
  }

  stdout.writeln('Source image size: ${decoded.width}x${decoded.height}');

  // Resize the supplied artwork without changing its composition.
  final standard = img.copyResize(
    decoded,
    width: 512,
    height: 512,
    interpolation: img.Interpolation.cubic,
  );
  File('assets/icon/app_icon.png').writeAsBytesSync(img.encodePng(standard));
  stdout.writeln('Saved assets/icon/app_icon.png (512x512)');

  // Sample corner pixel color for adaptive background matching
  final pixelTL = decoded.getPixel(10, 10);
  final r = pixelTL.r.toInt();
  final g = pixelTL.g.toInt();
  final b = pixelTL.b.toInt();
  final hexColor =
      '#${r.toRadixString(16).padLeft(2, '0')}${g.toRadixString(16).padLeft(2, '0')}${b.toRadixString(16).padLeft(2, '0')}'
          .toUpperCase();
  stdout.writeln('Corner background color: $hexColor (R:$r G:$g B:$b)');

  // flutter_launcher_icons adds a 16% inset to the foreground drawable.
  // A 90% image inside that drawable occupies 61.2% of the adaptive canvas,
  // inside Android's 66.7% central safe zone even under a circular mask.
  final canvasSize = 512;
  final safeSize = (canvasSize * 0.90).round();
  final offset = ((canvasSize - safeSize) / 2).round();

  final foreground = img.Image(
    width: canvasSize,
    height: canvasSize,
    numChannels: 4,
  );
  // Fill background with transparent
  img.fill(foreground, color: img.ColorRgba8(0, 0, 0, 0));

  final scaledSource = img.copyResize(
    decoded,
    width: safeSize,
    height: safeSize,
    interpolation: img.Interpolation.cubic,
  );

  img.compositeImage(foreground, scaledSource, dstX: offset, dstY: offset);

  File('assets/icon/app_icon_foreground.png')
      .writeAsBytesSync(img.encodePng(foreground));
  stdout.writeln(
    'Saved assets/icon/app_icon_foreground.png (512x512 with safe zone scaling)',
  );
}
