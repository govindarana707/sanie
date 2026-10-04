import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:supabase_flutter/supabase_flutter.dart';

import 'app/app.dart';
import 'app/design/sanie_theme.dart';
import 'app/splash/sanie_splash_screen.dart';
import 'core/config/env.dart';
import 'core/sync/automatic_sync_coordinator.dart';
import 'features/accounts/accounts_repository.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const ProviderScope(child: SanieStartup()));
}

enum _StartupState { initializing, ready, configurationError, failed }

class SanieStartup extends ConsumerStatefulWidget {
  const SanieStartup({super.key});

  @override
  ConsumerState<SanieStartup> createState() => _SanieStartupState();
}

class _SanieStartupState extends ConsumerState<SanieStartup> {
  _StartupState _state = _StartupState.initializing;

  @override
  void initState() {
    super.initState();
    _initialize();
  }

  Future<void> _initialize() async {
    const environment = AppEnvironment.fromEnvironment();
    if (!environment.isConfigured) {
      if (mounted) {
        setState(() => _state = _StartupState.configurationError);
      }
      return;
    }

    try {
      await Supabase.initialize(
        url: environment.supabaseUrl,
        publishableKey: environment.supabasePublishableKey,
      );

      final database = ref.read(accountsRepositoryProvider).database;
      await database.customSelect('SELECT 1').getSingle();

      if (mounted) setState(() => _state = _StartupState.ready);
    } catch (_) {
      if (mounted) setState(() => _state = _StartupState.failed);
    }
  }

  @override
  Widget build(BuildContext context) {
    final child = switch (_state) {
      _StartupState.ready => const AutomaticSyncCoordinator(
        key: ValueKey('sanie-app'),
        child: SanieApp(),
      ),
      _StartupState.configurationError => const ConfigurationErrorApp(
        key: ValueKey('configuration-error'),
      ),
      _StartupState.failed => StartupErrorApp(
        key: const ValueKey('startup-error'),
        onRetry: () {
          setState(() => _state = _StartupState.initializing);
          _initialize();
        },
      ),
      _StartupState.initializing => const SanieSplashApp(
        key: ValueKey('sanie-splash'),
      ),
    };

    return Directionality(
      textDirection: TextDirection.ltr,
      child: AnimatedSwitcher(
        duration: const Duration(milliseconds: 220),
        switchInCurve: Curves.easeOut,
        switchOutCurve: Curves.easeIn,
        child: child,
      ),
    );
  }
}

class SanieSplashApp extends StatelessWidget {
  const SanieSplashApp({super.key});

  @override
  Widget build(BuildContext context) => MaterialApp(
    debugShowCheckedModeBanner: false,
    theme: SanieTheme.light(),
    darkTheme: SanieTheme.dark(),
    themeMode: ThemeMode.system,
    home: const SanieSplashScreen(),
  );
}

class StartupErrorApp extends StatelessWidget {
  const StartupErrorApp({super.key, required this.onRetry});

  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) => MaterialApp(
    debugShowCheckedModeBanner: false,
    theme: SanieTheme.light(),
    darkTheme: SanieTheme.dark(),
    themeMode: ThemeMode.system,
    home: Scaffold(
      body: SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Text(
                  'SanIE could not finish starting.',
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 16),
                FilledButton(
                  onPressed: onRetry,
                  child: const Text('Try again'),
                ),
              ],
            ),
          ),
        ),
      ),
    ),
  );
}

class ConfigurationErrorApp extends StatelessWidget {
  const ConfigurationErrorApp({super.key});

  @override
  Widget build(BuildContext context) {
    return const MaterialApp(
      home: Scaffold(
        body: SafeArea(
          child: Center(
            child: Padding(
              padding: EdgeInsets.all(24),
              child: Text(
                'SanIE is not configured. Start it with SUPABASE_URL and '
                'SUPABASE_PUBLISHABLE_KEY supplied through --dart-define.',
                textAlign: TextAlign.center,
              ),
            ),
          ),
        ),
      ),
    );
  }
}
