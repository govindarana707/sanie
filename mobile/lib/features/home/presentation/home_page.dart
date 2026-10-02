import 'package:flutter/material.dart';
import 'package:supabase_flutter/supabase_flutter.dart';

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

    return Scaffold(
      appBar: AppBar(title: const Text('SanIE')),
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text('SanIE', style: Theme.of(context).textTheme.displaySmall),
              const SizedBox(height: 12),
              Text(email ?? 'Authenticated user'),
              const SizedBox(height: 24),
              OutlinedButton(
                onPressed: _isSigningOut ? null : _signOut,
                child: Text(_isSigningOut ? 'Signing out…' : 'Logout'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
