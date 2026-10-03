import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:drift/drift.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:supabase_flutter/supabase_flutter.dart';

import '../../features/accounts/accounts_repository.dart';
import '../database/app_database.dart';
import 'sync_providers.dart';

/// Requests the verified push-then-pull sync flow at app lifecycle boundaries.
/// Local writes remain independent of network availability.
class AutomaticSyncCoordinator extends ConsumerStatefulWidget {
  const AutomaticSyncCoordinator({super.key, required this.child});

  final Widget child;

  @override
  ConsumerState<AutomaticSyncCoordinator> createState() =>
      _AutomaticSyncCoordinatorState();
}

class _AutomaticSyncCoordinatorState
    extends ConsumerState<AutomaticSyncCoordinator>
    with WidgetsBindingObserver {
  final Connectivity _connectivity = Connectivity();

  StreamSubscription<AuthState>? _authSubscription;
  StreamSubscription<List<ConnectivityResult>>? _connectivitySubscription;
  StreamSubscription<List<OutboxCommand>>? _outboxSubscription;

  String? _userId;
  bool _online = false;
  bool _scheduled = false;
  bool _running = false;
  bool _runAgain = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);

    final auth = Supabase.instance.client.auth;
    _authSubscription = auth.onAuthStateChange.listen(
      (event) => _setUser(event.session?.user.id),
    );
    _connectivitySubscription = _connectivity.onConnectivityChanged.listen(
      _setConnectivity,
    );

    _setUser(auth.currentSession?.user.id);
    unawaited(_refreshConnectivity(requestWhenOnline: true));
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      unawaited(_refreshConnectivity(requestWhenOnline: true));
    }
  }

  void _setUser(String? userId) {
    if (_userId == userId) {
      if (userId != null) _requestSync();
      return;
    }

    _userId = userId;
    _runAgain = false;
    unawaited(_outboxSubscription?.cancel());
    _outboxSubscription = null;

    if (userId == null) return;

    final database = ref.read(accountsRepositoryProvider).database;
    _outboxSubscription =
        (database.select(database.outboxCommands)..where(
              (row) => row.userId.equals(userId) & row.status.equals('pending'),
            ))
            .watch()
            .listen((commands) {
              if (_userId == userId && commands.isNotEmpty) _requestSync();
            });
    _requestSync();
  }

  void _setConnectivity(List<ConnectivityResult> results) {
    final wasOnline = _online;
    _online = results.any((result) => result != ConnectivityResult.none);
    if (!wasOnline && _online) _requestSync();
  }

  Future<void> _refreshConnectivity({required bool requestWhenOnline}) async {
    final results = await _connectivity.checkConnectivity();
    if (!mounted) return;
    final wasOnline = _online;
    _online = results.any((result) => result != ConnectivityResult.none);
    if (_online && (requestWhenOnline || !wasOnline)) _requestSync();
  }

  void _requestSync() {
    if (!mounted || !_online || _userId == null) return;
    if (_running) {
      _runAgain = true;
      return;
    }
    if (_scheduled) return;
    _scheduled = true;
    scheduleMicrotask(() {
      _scheduled = false;
      if (mounted) unawaited(_drainSyncRequests());
    });
  }

  Future<void> _drainSyncRequests() async {
    if (_running) {
      _runAgain = true;
      return;
    }
    _running = true;
    try {
      do {
        _runAgain = false;
        final userId = _userId;
        if (!_online || userId == null) break;
        try {
          await ref.read(syncOrchestratorProvider).syncNow();
        } catch (_) {
          // Outbox state remains authoritative; a later lifecycle or network
          // trigger can retry without interrupting the user's local work.
        }
      } while (_runAgain && mounted && _online && _userId != null);
    } finally {
      _running = false;
      if (_runAgain) _requestSync();
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    unawaited(_authSubscription?.cancel());
    unawaited(_connectivitySubscription?.cancel());
    unawaited(_outboxSubscription?.cancel());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => widget.child;
}
