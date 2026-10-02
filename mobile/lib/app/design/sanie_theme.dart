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
  static const _brand = Color(0xFF176B4D);
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
    final scheme = ColorScheme.fromSeed(
      seedColor: _brand,
      brightness: brightness,
    );
    final base = ThemeData(useMaterial3: true, colorScheme: scheme);
    final surface = dark ? const Color(0xFF151D1A) : const Color(0xFFF7FAF8);
    final card = dark ? const Color(0xFF202B26) : Colors.white;
    final border = dark ? const Color(0xFF3D4B43) : const Color(0xFFDDE7E0);
    return base.copyWith(
      scaffoldBackgroundColor: surface,
      textTheme: base.textTheme.copyWith(
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
      extensions: [dark ? _darkFinance : _lightFinance],
      cardTheme: CardThemeData(
        color: card,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(SanieShape.card),
          side: BorderSide(color: border),
        ),
      ),
      appBarTheme: AppBarTheme(
        backgroundColor: surface,
        foregroundColor: scheme.onSurface,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: card,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: SanieSpace.md,
          vertical: SanieSpace.md,
        ),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(SanieShape.small),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(SanieShape.small),
          borderSide: BorderSide(color: border),
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
        backgroundColor: card,
        showDragHandle: true,
        shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(
            top: Radius.circular(SanieShape.sheet),
          ),
        ),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: card,
        elevation: 0,
        height: 72,
        labelTextStyle: WidgetStatePropertyAll(
          base.textTheme.labelSmall?.copyWith(fontWeight: FontWeight.w600),
        ),
      ),
      dividerColor: border,
    );
  }
}
