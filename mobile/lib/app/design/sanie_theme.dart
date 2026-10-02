import 'package:flutter/material.dart';

abstract final class SanieSpace {
  static const xs = 4.0;
  static const sm = 8.0;
  static const md = 16.0;
  static const lg = 24.0;
  static const xl = 32.0;
}

abstract final class SanieShape {
  static const small = 12.0;
  static const card = 20.0;
  static const sheet = 28.0;
  static const controlHeight = 52.0;
  static const touchTarget = 48.0;
  static const contentWidth = 640.0;
}

@immutable
class SaniePalette extends ThemeExtension<SaniePalette> {
  const SaniePalette({
    required this.background,
    required this.surface,
    required this.primaryText,
    required this.mutedText,
    required this.border,
    required this.emerald,
    required this.mint,
    required this.heroStart,
    required this.heroEnd,
    required this.heroAccent,
    required this.shadow,
  });

  final Color background;
  final Color surface;
  final Color primaryText;
  final Color mutedText;
  final Color border;
  final Color emerald;
  final Color mint;
  final Color heroStart;
  final Color heroEnd;
  final Color heroAccent;
  final Color shadow;

  @override
  SaniePalette copyWith({
    Color? background,
    Color? surface,
    Color? primaryText,
    Color? mutedText,
    Color? border,
    Color? emerald,
    Color? mint,
    Color? heroStart,
    Color? heroEnd,
    Color? heroAccent,
    Color? shadow,
  }) => SaniePalette(
    background: background ?? this.background,
    surface: surface ?? this.surface,
    primaryText: primaryText ?? this.primaryText,
    mutedText: mutedText ?? this.mutedText,
    border: border ?? this.border,
    emerald: emerald ?? this.emerald,
    mint: mint ?? this.mint,
    heroStart: heroStart ?? this.heroStart,
    heroEnd: heroEnd ?? this.heroEnd,
    heroAccent: heroAccent ?? this.heroAccent,
    shadow: shadow ?? this.shadow,
  );

  @override
  SaniePalette lerp(ThemeExtension<SaniePalette>? other, double t) {
    if (other is! SaniePalette) return this;
    return SaniePalette(
      background: Color.lerp(background, other.background, t)!,
      surface: Color.lerp(surface, other.surface, t)!,
      primaryText: Color.lerp(primaryText, other.primaryText, t)!,
      mutedText: Color.lerp(mutedText, other.mutedText, t)!,
      border: Color.lerp(border, other.border, t)!,
      emerald: Color.lerp(emerald, other.emerald, t)!,
      mint: Color.lerp(mint, other.mint, t)!,
      heroStart: Color.lerp(heroStart, other.heroStart, t)!,
      heroEnd: Color.lerp(heroEnd, other.heroEnd, t)!,
      heroAccent: Color.lerp(heroAccent, other.heroAccent, t)!,
      shadow: Color.lerp(shadow, other.shadow, t)!,
    );
  }
}

@immutable
class FinanceColors extends ThemeExtension<FinanceColors> {
  const FinanceColors({
    required this.income,
    required this.incomeSurface,
    required this.expense,
    required this.expenseSurface,
    required this.transfer,
    required this.transferSurface,
  });

  final Color income;
  final Color incomeSurface;
  final Color expense;
  final Color expenseSurface;
  final Color transfer;
  final Color transferSurface;

  @override
  FinanceColors copyWith({
    Color? income,
    Color? incomeSurface,
    Color? expense,
    Color? expenseSurface,
    Color? transfer,
    Color? transferSurface,
  }) => FinanceColors(
    income: income ?? this.income,
    incomeSurface: incomeSurface ?? this.incomeSurface,
    expense: expense ?? this.expense,
    expenseSurface: expenseSurface ?? this.expenseSurface,
    transfer: transfer ?? this.transfer,
    transferSurface: transferSurface ?? this.transferSurface,
  );

  @override
  FinanceColors lerp(ThemeExtension<FinanceColors>? other, double t) {
    if (other is! FinanceColors) return this;
    return FinanceColors(
      income: Color.lerp(income, other.income, t)!,
      incomeSurface: Color.lerp(incomeSurface, other.incomeSurface, t)!,
      expense: Color.lerp(expense, other.expense, t)!,
      expenseSurface: Color.lerp(expenseSurface, other.expenseSurface, t)!,
      transfer: Color.lerp(transfer, other.transfer, t)!,
      transferSurface: Color.lerp(transferSurface, other.transferSurface, t)!,
    );
  }
}

abstract final class SanieTheme {
  static const _brand = Color(0xFF079C61);
  static const _lightPalette = SaniePalette(
    background: Color(0xFFFAFCFB),
    surface: Colors.white,
    primaryText: Color(0xFF07111D),
    mutedText: Color(0xFF758395),
    border: Color(0xFFF0F3F2),
    emerald: Color(0xFF079C61),
    mint: Color(0xFFE7F8F0),
    heroStart: Color(0xFF064B39),
    heroEnd: Color(0xFF087F51),
    heroAccent: Color(0xFF48D99A),
    shadow: Color(0x120C2730),
  );
  static const _darkPalette = SaniePalette(
    background: Color(0xFF111416),
    surface: Color(0xFF1D2225),
    primaryText: Color(0xFFF5F8F7),
    mutedText: Color(0xFFACB9BE),
    border: Color(0xFF30383A),
    emerald: Color(0xFF53D99A),
    mint: Color(0xFF19382C),
    heroStart: Color(0xFF06392E),
    heroEnd: Color(0xFF096647),
    heroAccent: Color(0xFF64E7AD),
    shadow: Color(0x30000000),
  );
  static const _lightFinance = FinanceColors(
    income: Color(0xFF176B4D),
    incomeSurface: Color(0xFFE6F4EC),
    expense: Color(0xFFB83F46),
    expenseSurface: Color(0xFFFBEAEC),
    transfer: Color(0xFF2B64A4),
    transferSurface: Color(0xFFE8F0FA),
  );
  static const _darkFinance = FinanceColors(
    income: Color(0xFF78D5A4),
    incomeSurface: Color(0xFF173B2C),
    expense: Color(0xFFFFA9AE),
    expenseSurface: Color(0xFF49272D),
    transfer: Color(0xFFA4C9FF),
    transferSurface: Color(0xFF233B59),
  );

  static ThemeData light() => _build(Brightness.light);
  static ThemeData dark() => _build(Brightness.dark);

  static ThemeData _build(Brightness brightness) {
    final dark = brightness == Brightness.dark;
    final palette = dark ? _darkPalette : _lightPalette;
    final scheme = ColorScheme.fromSeed(
      seedColor: _brand,
      brightness: brightness,
    );
    final base = ThemeData(useMaterial3: true, colorScheme: scheme);
    return base.copyWith(
      colorScheme: scheme.copyWith(
        primary: palette.emerald,
        surface: palette.surface,
        onSurface: palette.primaryText,
      ),
      scaffoldBackgroundColor: palette.background,
      textTheme: base.textTheme
          .apply(
            bodyColor: palette.primaryText,
            displayColor: palette.primaryText,
          )
          .copyWith(
            headlineMedium: base.textTheme.headlineMedium?.copyWith(
              fontWeight: FontWeight.w700,
              letterSpacing: -0.7,
            ),
            titleLarge: base.textTheme.titleLarge?.copyWith(
              fontWeight: FontWeight.w700,
            ),
            titleMedium: base.textTheme.titleMedium?.copyWith(
              fontWeight: FontWeight.w600,
            ),
            labelLarge: base.textTheme.labelLarge?.copyWith(
              fontWeight: FontWeight.w600,
            ),
          ),
      extensions: [dark ? _darkFinance : _lightFinance, palette],
      cardTheme: CardThemeData(
        color: palette.surface,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(SanieShape.card),
          side: BorderSide(color: palette.border),
        ),
      ),
      appBarTheme: AppBarTheme(
        backgroundColor: palette.background,
        foregroundColor: scheme.onSurface,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: palette.surface,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: SanieSpace.md,
          vertical: SanieSpace.md,
        ),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(SanieShape.small),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(SanieShape.small),
          borderSide: BorderSide(color: palette.border),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size(0, SanieShape.controlHeight),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(SanieShape.small),
          ),
        ),
      ),
      bottomSheetTheme: BottomSheetThemeData(
        backgroundColor: palette.surface,
        showDragHandle: true,
        shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(
            top: Radius.circular(SanieShape.sheet),
          ),
        ),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: palette.surface,
        elevation: 0,
        height: 72,
        labelTextStyle: WidgetStatePropertyAll(
          base.textTheme.labelSmall?.copyWith(fontWeight: FontWeight.w600),
        ),
      ),
      dividerColor: palette.border,
    );
  }
}
