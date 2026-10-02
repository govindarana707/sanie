import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:supabase_flutter/supabase_flutter.dart';

void main() {
  const url = String.fromEnvironment('SANIE_SMOKE_URL');
  const publishableKey = String.fromEnvironment('SANIE_SMOKE_PUBLISHABLE_KEY');
  final email = Platform.environment['SANIE_SMOKE_EMAIL'];
  final password = Platform.environment['SANIE_SMOKE_PASSWORD'];
  final configured =
      url.isNotEmpty &&
      publishableKey.isNotEmpty &&
      email != null &&
      email.isNotEmpty &&
      password != null &&
      password.isNotEmpty;

  test(
    'manual disposable-user deletion removes normal-client authentication',
    () async {
      final client = SupabaseClient(url, publishableKey);
      await expectLater(
        client.auth.signInWithPassword(email: email!, password: password!),
        throwsA(isA<AuthApiException>()),
      );
      expect(client.auth.currentSession, isNull);
    },
    skip: !configured,
  );
}
