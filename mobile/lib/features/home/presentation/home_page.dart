import 'package:flutter/material.dart';
import 'package:supabase_flutter/supabase_flutter.dart';

import '../../../app/design/finance_widgets.dart';
import '../../../app/design/sanie_theme.dart';

class HomePage extends StatefulWidget {
  const HomePage({super.key});

  @override
  State<HomePage> createState() => _HomePageState();
}

class _HomePageState extends State<HomePage> {
  bool _isSigningOut = false;

  Future<void> _signOut() async {
    setState(() => _isSigningOut = true);
    try {
      await Supabase.instance.client.auth.signOut();
    } finally {
      if (mounted) setState(() => _isSigningOut = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final email = Supabase.instance.client.auth.currentUser?.email;

    return FinanceScreen(
      children: [
        Text(
          'Good to see you',
          style: Theme.of(context).textTheme.headlineMedium,
        ),
        const SizedBox(height: SanieSpace.sm),
        Text(
          email ?? 'Authenticated user',
          style: Theme.of(context).textTheme.bodyMedium,
        ),
        const SizedBox(height: SanieSpace.lg),
        const FinanceStateView(
          state: FinanceViewState.empty,
          message: 'Your financial overview will appear here soon.',
        ),
        const SizedBox(height: SanieSpace.lg),
        Align(
          alignment: Alignment.centerLeft,
          child: OutlinedButton.icon(
            onPressed: _isSigningOut ? null : _signOut,
            icon: const Icon(Icons.logout_rounded),
            label: Text(_isSigningOut ? 'Signing out…' : 'Logout'),
          ),
        ),
      ],
    );
  }
}
