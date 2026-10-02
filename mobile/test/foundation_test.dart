import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sanie/core/config/env.dart';
import 'package:sanie/features/auth/presentation/login_page.dart';
import 'package:sanie/main.dart';

void main() {
  test('environment requires an HTTPS URL and publishable key', () {
    const missing = AppEnvironment(supabaseUrl: '', supabasePublishableKey: '');
    const configured = AppEnvironment(
      supabaseUrl: 'https://example.supabase.co',
      supabasePublishableKey: 'publishable-test-value',
    );

    expect(missing.isConfigured, isFalse);
    expect(configured.isConfigured, isTrue);
  });

  testWidgets('missing configuration displays actionable guidance', (
    tester,
  ) async {
    await tester.pumpWidget(const ConfigurationErrorApp());

    expect(find.textContaining('SUPABASE_URL'), findsOneWidget);
    expect(find.textContaining('SUPABASE_PUBLISHABLE_KEY'), findsOneWidget);
  });

  testWidgets('login page exposes the minimal sign-in controls', (
    tester,
  ) async {
    await tester.pumpWidget(const MaterialApp(home: LoginPage()));

    expect(find.byType(TextField), findsNWidgets(2));
    expect(find.text('Sign in'), findsOneWidget);
  });
}
