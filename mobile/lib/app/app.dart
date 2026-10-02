import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'design/sanie_theme.dart';
import 'router.dart';

class SanieApp extends ConsumerWidget {
  const SanieApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final router = ref.watch(routerProvider);

    return MaterialApp.router(
      title: 'SanIE',
      debugShowCheckedModeBanner: false,
      theme: SanieTheme.light(),
      darkTheme: SanieTheme.dark(),
      themeMode: ThemeMode.system,
      routerConfig: router,
    );
  }
}
