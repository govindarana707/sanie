import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

class SanieSplashScreen extends StatelessWidget {
  const SanieSplashScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    final background = dark ? const Color(0xFF0E1311) : const Color(0xFFFBFAF5);
    final foreground = dark ? const Color(0xFFF3F7F5) : const Color(0xFF08241D);
    final muted = dark ? const Color(0xFFAAB5B0) : const Color(0xFF69736F);

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: dark ? SystemUiOverlayStyle.light : SystemUiOverlayStyle.dark,
      child: Scaffold(
        backgroundColor: background,
        body: LayoutBuilder(
          builder: (context, constraints) {
            final height = constraints.maxHeight;
            final compact = height < 650;
            final logoSize = math.min(constraints.maxWidth * 0.34, 152.0);
            return Stack(
              fit: StackFit.expand,
              children: [
                CustomPaint(painter: _SanieLandscapePainter(dark: dark)),
                SafeArea(
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 24),
                    child: Column(
                      children: [
                        const Spacer(flex: 7),
                        SizedBox.square(
                          dimension: compact ? logoSize * 0.82 : logoSize,
                          child: const CustomPaint(
                            painter: _SanieMarkPainter(),
                          ),
                        ),
                        SizedBox(height: compact ? 8 : 14),
                        ShaderMask(
                          blendMode: BlendMode.srcIn,
                          shaderCallback: (bounds) => LinearGradient(
                            colors: dark
                                ? const [Color(0xFFF3F7F5), Color(0xFF53D99A)]
                                : const [Color(0xFF062C23), Color(0xFF08A667)],
                          ).createShader(bounds),
                          child: Text(
                            'SanIE',
                            style: TextStyle(
                              color: foreground,
                              fontSize: compact ? 50 : 62,
                              height: 0.95,
                              fontWeight: FontWeight.w800,
                              letterSpacing: -3.2,
                            ),
                          ),
                        ),
                        SizedBox(height: compact ? 9 : 14),
                        FittedBox(
                          fit: BoxFit.scaleDown,
                          child: Text(
                            'Personal Finance Manager',
                            maxLines: 1,
                            style: TextStyle(
                              color: muted,
                              fontSize: compact ? 17 : 20,
                              fontWeight: FontWeight.w400,
                              letterSpacing: 0.1,
                            ),
                          ),
                        ),
                        SizedBox(height: compact ? 28 : 40),
                        SizedBox(
                          width: 154,
                          child: ClipRRect(
                            borderRadius: BorderRadius.circular(20),
                            child: LinearProgressIndicator(
                              minHeight: 6,
                              color: const Color(0xFF0CB76E),
                              backgroundColor: dark
                                  ? const Color(0xFF26322E)
                                  : const Color(0xFFDDE7E2),
                            ),
                          ),
                        ),
                        const Spacer(flex: 5),
                      ],
                    ),
                  ),
                ),
                Positioned(
                  left: 24,
                  right: 24,
                  bottom: math.max(142.0, height * 0.27),
                  child: SafeArea(
                    top: false,
                    child: Text.rich(
                      TextSpan(
                        text: 'Developed by ',
                        children: const [
                          TextSpan(
                            text: 'Govinda Rana',
                            style: TextStyle(fontWeight: FontWeight.w600),
                          ),
                        ],
                      ),
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        color: dark
                            ? const Color(0xFFB5BFBB)
                            : const Color(0xFF747D79),
                        fontSize: 12.5,
                        letterSpacing: 0.15,
                      ),
                    ),
                  ),
                ),
              ],
            );
          },
        ),
      ),
    );
  }
}

class _SanieMarkPainter extends CustomPainter {
  const _SanieMarkPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final scale = size.width / 152;
    canvas.scale(scale, scale);

    final barPaint = Paint()
      ..shader = const LinearGradient(
        begin: Alignment.topCenter,
        end: Alignment.bottomCenter,
        colors: [Color(0xFF18B76F), Color(0xFF006143)],
      ).createShader(const Rect.fromLTWH(26, 24, 100, 104));

    for (final bar in const [
      Rect.fromLTWH(35, 61, 27, 55),
      Rect.fromLTWH(66, 41, 27, 76),
      Rect.fromLTWH(97, 17, 27, 90),
    ]) {
      canvas.drawRRect(
        RRect.fromRectAndRadius(bar, const Radius.circular(5)),
        barPaint,
      );
    }

    final leaf = Path()
      ..moveTo(12, 87)
      ..cubicTo(28, 108, 57, 122, 82, 113)
      ..cubicTo(107, 104, 126, 86, 136, 55)
      ..cubicTo(140, 89, 126, 119, 99, 132)
      ..cubicTo(68, 147, 30, 130, 12, 87)
      ..close();
    final leafPaint = Paint()
      ..shader = const LinearGradient(
        begin: Alignment.topRight,
        end: Alignment.bottomLeft,
        colors: [Color(0xFF0DBD6D), Color(0xFF004938)],
      ).createShader(const Rect.fromLTWH(10, 54, 130, 92));
    canvas.drawPath(leaf, leafPaint);

    final vein = Path()
      ..moveTo(62, 126)
      ..cubicTo(82, 111, 103, 96, 121, 76);
    canvas.drawPath(
      vein,
      Paint()
        ..color = Colors.white.withValues(alpha: 0.94)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 4
        ..strokeCap = StrokeCap.round,
    );
  }

  @override
  bool shouldRepaint(covariant _SanieMarkPainter oldDelegate) => false;
}

class _SanieLandscapePainter extends CustomPainter {
  const _SanieLandscapePainter({required this.dark});

  final bool dark;

  @override
  void paint(Canvas canvas, Size size) {
    final mountainTop = size.height * 0.56;
    _mountain(
      canvas,
      size,
      mountainTop,
      dark ? const Color(0xFF17231F) : const Color(0xFFEAF0EC),
      0.6,
    );
    _mountain(
      canvas,
      size,
      mountainTop + size.height * 0.055,
      dark ? const Color(0xFF14201C) : const Color(0xFFE1E9E4),
      1.15,
    );
    _mountain(
      canvas,
      size,
      mountainTop + size.height * 0.11,
      dark ? const Color(0xFF111C18) : const Color(0xFFD9E5DF),
      1.8,
    );

    _wave(
      canvas,
      size,
      start: 0.68,
      left: 0.62,
      middle: 0.86,
      right: 0.72,
      colors: dark
          ? const [Color(0xFF123D30), Color(0xFF17694D)]
          : const [Color(0xFFC4E8D7), Color(0xFF54C697)],
    );
    _wave(
      canvas,
      size,
      start: 0.75,
      left: 0.78,
      middle: 0.91,
      right: 0.79,
      colors: dark
          ? const [Color(0xFF07543B), Color(0xFF0A2D25)]
          : const [Color(0xFF079A61), Color(0xFF005B42)],
    );
    _wave(
      canvas,
      size,
      start: 0.84,
      left: 0.94,
      middle: 0.82,
      right: 0.9,
      colors: dark
          ? const [Color(0xFF0B372A), Color(0xFF087754)]
          : const [Color(0xFF35BA83), Color(0xFF00734F)],
    );
  }

  void _mountain(
    Canvas canvas,
    Size size,
    double top,
    Color color,
    double phase,
  ) {
    final path = Path()..moveTo(0, size.height);
    path.lineTo(0, top + size.height * 0.05);
    for (var i = 0; i <= 16; i++) {
      final x = size.width * i / 16;
      final y =
          top +
          math.sin(i * 0.72 + phase) * size.height * 0.025 +
          math.sin(i * 0.29 + phase) * size.height * 0.018;
      path.lineTo(x, y);
    }
    path
      ..lineTo(size.width, size.height)
      ..close();
    canvas.drawPath(path, Paint()..color = color.withValues(alpha: 0.74));
  }

  void _wave(
    Canvas canvas,
    Size size, {
    required double start,
    required double left,
    required double middle,
    required double right,
    required List<Color> colors,
  }) {
    final path = Path()
      ..moveTo(0, size.height * left)
      ..cubicTo(
        size.width * 0.25,
        size.height * start,
        size.width * 0.42,
        size.height * middle,
        size.width * 0.62,
        size.height * middle,
      )
      ..cubicTo(
        size.width * 0.78,
        size.height * middle,
        size.width * 0.9,
        size.height * right,
        size.width,
        size.height * right,
      )
      ..lineTo(size.width, size.height)
      ..lineTo(0, size.height)
      ..close();
    canvas.drawPath(
      path,
      Paint()
        ..shader = LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: colors,
        ).createShader(Offset.zero & size),
    );
    canvas.drawPath(
      path,
      Paint()
        ..color = Colors.white.withValues(alpha: dark ? 0.12 : 0.55)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1.5,
    );
  }

  @override
  bool shouldRepaint(covariant _SanieLandscapePainter oldDelegate) =>
      oldDelegate.dark != dark;
}
