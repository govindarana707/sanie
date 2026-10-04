import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/design/finance_widgets.dart';
import '../../app/design/sanie_theme.dart';
import '../../core/database/app_database.dart';
import '../../core/sync/sync_providers.dart';
import '../accounts/accounts_repository.dart';
import 'categories_repository.dart';

const _iconChoices = <String, String>{
  'category': 'Category',
  'home': 'Home',
  'restaurant': 'Food',
  'shopping_cart': 'Shopping',
  'work': 'Work',
  'payments': 'Payments',
  'favorite': 'Health',
  'directions_car': 'Transport',
  'school': 'Education',
  'more_horiz': 'Other',
};

IconData _iconFor(String? code) => switch (code) {
  'home' => Icons.home_outlined,
  'restaurant' => Icons.restaurant_outlined,
  'shopping_cart' => Icons.shopping_cart_outlined,
  'work' => Icons.work_outline_rounded,
  'payments' => Icons.payments_outlined,
  'favorite' => Icons.favorite_outline_rounded,
  'directions_car' => Icons.directions_car_outlined,
  'school' => Icons.school_outlined,
  'more_horiz' => Icons.more_horiz_rounded,
  _ => Icons.category_outlined,
};

String _typeLabel(String type) => type == 'income' ? 'Income' : 'Expense';
String _categoryPath(String id) => '/categories/${Uri.encodeComponent(id)}';
String _subcategoryPath(String parentId, String id) =>
    '${_categoryPath(parentId)}/subcategories/${Uri.encodeComponent(id)}';

Widget _authView(AsyncValue<String?> user, Widget Function(String) content) =>
    user.when(
      data: (id) => id == null
          ? const FinanceScreen(
              children: [
                FinanceStateView(
                  state: FinanceViewState.error,
                  message: 'Sign in to view categories.',
                ),
              ],
            )
          : content(id),
      loading: () => const FinanceScreen(
        children: [
          FinanceStateView(
            state: FinanceViewState.loading,
            message: 'Loading categories…',
          ),
        ],
      ),
      error: (_, _) => const FinanceScreen(
        children: [
          FinanceStateView(
            state: FinanceViewState.error,
            message: 'Could not check your session.',
          ),
        ],
      ),
    );

Widget _sessionChanged() => const FinanceScreen(
  children: [
    FinanceStateView(
      state: FinanceViewState.loading,
      message: 'Switching account…',
    ),
  ],
);

class _Heading extends StatelessWidget {
  const _Heading({required this.title, required this.backTo});
  final String title;
  final String backTo;

  @override
  Widget build(BuildContext context) => Row(
    children: [
      IconButton(
        key: const Key('category-back'),
        tooltip: 'Back',
        onPressed: () => context.canPop() ? context.pop() : context.go(backTo),
        icon: const Icon(Icons.arrow_back_rounded),
      ),
      const SizedBox(width: SanieSpace.xs),
      Expanded(
        child: Text(
          title,
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          style: Theme.of(context).textTheme.headlineMedium,
        ),
      ),
    ],
  );
}

class _CategoryIcon extends StatelessWidget {
  const _CategoryIcon({required this.icon});
  final String? icon;

  @override
  Widget build(BuildContext context) {
    final palette = Theme.of(context).extension<SaniePalette>()!;
    return CircleAvatar(
      backgroundColor: palette.mint,
      foregroundColor: palette.emerald,
      child: Icon(_iconFor(icon)),
    );
  }
}

class CategoriesPage extends ConsumerWidget {
  const CategoriesPage({super.key});

  @override
  Widget build(
    BuildContext context,
    WidgetRef ref,
  ) => _authView(ref.watch(accountUserProvider), (userId) {
    final repository = ref.watch(categoriesRepositoryProvider);
    if (repository.authenticatedUserId() != userId) return _sessionChanged();
    final bootstrap = ref.watch(categoriesBootstrapProvider(userId));
    return StreamBuilder<List<Category>>(
      key: ValueKey('categories:$userId'),
      stream: repository.watchCategories(userId),
      builder: (context, snapshot) => StreamBuilder<List<OutboxCommand>>(
        stream: repository.watchCommands(userId),
        builder: (context, commandSnapshot) {
          final categories = snapshot.data;
          final commands = commandSnapshot.data ?? [];
          return FinanceScreen(
            children: [
              const _Heading(title: 'Categories', backTo: '/more'),
              const SizedBox(height: SanieSpace.md),
              Text(
                'Organize income and expenses with categories and subcategories.',
                style: Theme.of(context).textTheme.bodyMedium,
              ),
              const SizedBox(height: SanieSpace.lg),
              if (snapshot.hasError)
                const FinanceStateView(
                  state: FinanceViewState.error,
                  message: 'Categories could not be loaded from this device.',
                )
              else if (categories == null)
                const FinanceStateView(
                  state: FinanceViewState.loading,
                  message: 'Loading categories…',
                )
              else ...[
                if (bootstrap.hasError)
                  Padding(
                    padding: const EdgeInsets.only(bottom: SanieSpace.md),
                    child: Text(
                      'Showing saved categories. System categories could not be refreshed.',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ),
                if (categories.isEmpty)
                  FinanceStateView(
                    state: bootstrap.isLoading
                        ? FinanceViewState.loading
                        : FinanceViewState.empty,
                    message: bootstrap.isLoading
                        ? 'Loading categories…'
                        : 'No categories yet. Add one to get started.',
                  )
                else
                  for (final type in const ['expense', 'income'])
                    if (categories.any((row) => row.categoryType == type)) ...[
                      FinanceSectionHeader(title: _typeLabel(type)),
                      const SizedBox(height: SanieSpace.md),
                      for (final category in categories.where(
                        (row) => row.categoryType == type,
                      )) ...[
                        _CategoryTile(
                          category: category,
                          queue: categoryQueueState(commands, category.id),
                        ),
                        const SizedBox(height: SanieSpace.sm),
                      ],
                      const SizedBox(height: SanieSpace.md),
                    ],
                const SizedBox(height: SanieSpace.sm),
                FinancePrimaryButton(
                  label: 'Add category',
                  icon: Icons.add_rounded,
                  onPressed: () => context.push('/categories/add'),
                ),
              ],
            ],
          );
        },
      ),
    );
  });
}

class _CategoryTile extends StatelessWidget {
  const _CategoryTile({required this.category, required this.queue});
  final Category category;
  final CategoryQueueState queue;

  @override
  Widget build(BuildContext context) => FinanceCard(
    padding: EdgeInsets.zero,
    child: ListTile(
      key: Key('category-${category.id}'),
      contentPadding: const EdgeInsets.symmetric(
        horizontal: SanieSpace.md,
        vertical: SanieSpace.xs,
      ),
      leading: _CategoryIcon(icon: category.icon),
      title: Text(category.name, maxLines: 2, overflow: TextOverflow.ellipsis),
      subtitle: Text(
        '${category.isSystem ? 'System' : 'Custom'} · ${category.status == 'active' ? 'Active' : 'Archived'}${queue == CategoryQueueState.pending
            ? ' · Pending sync'
            : queue == CategoryQueueState.failed
            ? ' · Needs attention'
            : ''}',
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
      ),
      trailing: const Icon(Icons.chevron_right_rounded),
      onTap: () => context.push(_categoryPath(category.id)),
    ),
  );
}

class CategoryDetailsPage extends ConsumerStatefulWidget {
  const CategoryDetailsPage({super.key, required this.id});
  final String id;

  @override
  ConsumerState<CategoryDetailsPage> createState() =>
      _CategoryDetailsPageState();
}

class _CategoryDetailsPageState extends ConsumerState<CategoryDetailsPage> {
  String? _busyId;
  String? _error;

  Future<void> _archive({
    required CategoriesRepository repository,
    required String id,
    required String label,
    required bool subcategory,
  }) async {
    if (_busyId != null) return;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text('Archive $label?'),
        content: const Text(
          'This hides it from new transactions. Existing financial history stays intact.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('Archive'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted || _busyId != null) return;
    setState(() {
      _busyId = id;
      _error = null;
    });
    try {
      if (subcategory) {
        await repository.mutations.archiveSubcategory(id);
      } else {
        await repository.mutations.archiveCategory(id);
      }
    } catch (error) {
      if (mounted) setState(() => _error = _categoryError(error));
    } finally {
      if (mounted) setState(() => _busyId = null);
    }
  }

  @override
  Widget build(
    BuildContext context,
  ) => _authView(ref.watch(accountUserProvider), (userId) {
    final repository = ref.watch(categoriesRepositoryProvider);
    if (repository.authenticatedUserId() != userId) return _sessionChanged();
    return StreamBuilder<Category?>(
      key: ValueKey('category:$userId:${widget.id}'),
      stream: repository.watchCategory(userId, widget.id),
      builder: (context, snapshot) {
        final category = snapshot.data;
        return FinanceScreen(
          children: [
            const _Heading(title: 'Category details', backTo: '/categories'),
            const SizedBox(height: SanieSpace.lg),
            if (snapshot.hasError)
              const FinanceStateView(
                state: FinanceViewState.error,
                message: 'Category could not be loaded.',
              )
            else if (snapshot.connectionState == ConnectionState.waiting)
              const FinanceStateView(
                state: FinanceViewState.loading,
                message: 'Loading category…',
              )
            else if (category == null)
              const FinanceStateView(
                state: FinanceViewState.empty,
                message: 'Category unavailable.',
              )
            else
              StreamBuilder<List<OutboxCommand>>(
                stream: repository.watchCommands(userId),
                builder: (context, commandSnapshot) {
                  final commands = commandSnapshot.data ?? [];
                  final queue = categoryQueueState(commands, category.id);
                  final canEdit =
                      !category.isSystem &&
                      category.status == 'active' &&
                      queue == CategoryQueueState.none &&
                      _busyId == null;
                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      FinanceCard(
                        child: Row(
                          children: [
                            _CategoryIcon(icon: category.icon),
                            const SizedBox(width: SanieSpace.md),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    category.name,
                                    maxLines: 3,
                                    overflow: TextOverflow.ellipsis,
                                    style: Theme.of(context)
                                        .textTheme
                                        .titleLarge,
                                  ),
                                  const SizedBox(height: SanieSpace.xs),
                                  Text(
                                    '${_typeLabel(category.categoryType)} · ${category.isSystem ? 'System' : 'Custom'} · ${category.status == 'active' ? 'Active' : 'Archived'}',
                                    style: Theme.of(context)
                                        .textTheme
                                        .bodySmall,
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                      ),
                      if (category.description?.isNotEmpty == true) ...[
                        const SizedBox(height: SanieSpace.sm),
                        FinanceCard(child: Text(category.description!)),
                      ],
                      if (queue != CategoryQueueState.none) ...[
                        const SizedBox(height: SanieSpace.sm),
                        Text(
                          queue == CategoryQueueState.pending
                              ? 'Category change pending sync.'
                              : 'Category change needs attention.',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                      ],
                      if (!category.isSystem &&
                          category.status == 'active') ...[
                        const SizedBox(height: SanieSpace.lg),
                        OutlinedButton(
                          key: const Key('edit-category'),
                          onPressed: canEdit
                              ? () => context.push(
                                  '${_categoryPath(widget.id)}/edit',
                                )
                              : null,
                          child: const Text('Edit category'),
                        ),
                        const SizedBox(height: SanieSpace.sm),
                        OutlinedButton(
                          key: const Key('archive-category'),
                          onPressed: canEdit
                              ? () => _archive(
                                  repository: repository,
                                  id: category.id,
                                  label: 'category',
                                  subcategory: false,
                                )
                              : null,
                          child: const Text('Archive category'),
                        ),
                      ],
                      const SizedBox(height: SanieSpace.lg),
                      FinanceSectionHeader(
                        title: 'Subcategories',
                        action: category.status == 'active'
                            ? TextButton.icon(
                                key: const Key('add-subcategory'),
                                onPressed: _busyId == null
                                    ? () => context.push(
                                        '${_categoryPath(widget.id)}/subcategories/add',
                                      )
                                    : null,
                                icon: const Icon(Icons.add_rounded),
                                label: const Text('Add'),
                              )
                            : null,
                      ),
                      const SizedBox(height: SanieSpace.md),
                      StreamBuilder<List<Subcategory>>(
                        stream: repository.watchSubcategories(
                          userId,
                          category.id,
                        ),
                        builder: (context, subSnapshot) {
                          if (subSnapshot.hasError) {
                            return const FinanceStateView(
                              state: FinanceViewState.error,
                              message: 'Subcategories could not be loaded.',
                            );
                          }
                          if (subSnapshot.data == null) {
                            return const FinanceStateView(
                              state: FinanceViewState.loading,
                              message: 'Loading subcategories…',
                            );
                          }
                          final subcategories = subSnapshot.data!;
                          if (subcategories.isEmpty) {
                            return const FinanceStateView(
                              state: FinanceViewState.empty,
                              message: 'No subcategories yet.',
                            );
                          }
                          return Column(
                            children: [
                              for (final sub in subcategories) ...[
                                _SubcategoryTile(
                                  subcategory: sub,
                                  queue: categoryQueueState(commands, sub.id),
                                  editable:
                                      sub.userId == userId &&
                                      sub.status == 'active' &&
                                      category.status == 'active' &&
                                      _busyId == null,
                                  onEdit: () => context.push(
                                    '${_subcategoryPath(category.id, sub.id)}/edit',
                                  ),
                                  onArchive: () => _archive(
                                    repository: repository,
                                    id: sub.id,
                                    label: 'subcategory',
                                    subcategory: true,
                                  ),
                                ),
                                const SizedBox(height: SanieSpace.sm),
                              ],
                            ],
                          );
                        },
                      ),
                      if (_error != null) ...[
                        const SizedBox(height: SanieSpace.md),
                        Text(
                          _error!,
                          key: const Key('category-action-error'),
                          style: TextStyle(
                            color: Theme.of(context).colorScheme.error,
                          ),
                        ),
                      ],
                    ],
                  );
                },
              ),
          ],
        );
      },
    );
  });
}

class _SubcategoryTile extends StatelessWidget {
  const _SubcategoryTile({
    required this.subcategory,
    required this.queue,
    required this.editable,
    required this.onEdit,
    required this.onArchive,
  });
  final Subcategory subcategory;
  final CategoryQueueState queue;
  final bool editable;
  final VoidCallback onEdit;
  final VoidCallback onArchive;

  @override
  Widget build(BuildContext context) => FinanceCard(
    padding: EdgeInsets.zero,
    child: ListTile(
      key: Key('subcategory-${subcategory.id}'),
      contentPadding: const EdgeInsets.symmetric(horizontal: SanieSpace.md),
      leading: _CategoryIcon(icon: subcategory.icon),
      title: Text(
        subcategory.name,
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
      ),
      subtitle: Text(
        '${subcategory.userId == null ? 'System' : 'Custom'} · ${subcategory.status == 'active' ? 'Active' : 'Archived'}${queue == CategoryQueueState.pending
            ? ' · Pending sync'
            : queue == CategoryQueueState.failed
            ? ' · Needs attention'
            : ''}',
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
      ),
      trailing: editable && queue == CategoryQueueState.none
          ? PopupMenuButton<String>(
              tooltip: 'Subcategory actions',
              onSelected: (action) => action == 'edit' ? onEdit() : onArchive(),
              itemBuilder: (_) => const [
                PopupMenuItem(value: 'edit', child: Text('Edit')),
                PopupMenuItem(value: 'archive', child: Text('Archive')),
              ],
            )
          : null,
    ),
  );
}

class CategoryFormPage extends ConsumerWidget {
  const CategoryFormPage({super.key, this.id});
  final String? id;

  @override
  Widget build(BuildContext context, WidgetRef ref) => _authView(
    ref.watch(accountUserProvider),
    (userId) {
      final repository = ref.watch(categoriesRepositoryProvider);
      if (repository.authenticatedUserId() != userId) return _sessionChanged();
      final sync = ref.watch(userSyncStateProvider(userId));
      return sync.when(
        data: (state) {
          if (state == null) {
            final initialization = ref.watch(syncInitializerProvider(userId));
            return FinanceScreen(
              children: [
                _Heading(
                  title: id == null ? 'Add category' : 'Edit category',
                  backTo: id == null ? '/categories' : _categoryPath(id!),
                ),
                const SizedBox(height: SanieSpace.lg),
                FinanceStateView(
                  state: initialization.hasError
                      ? FinanceViewState.error
                      : FinanceViewState.loading,
                  message: initialization.hasError
                      ? 'Could not prepare offline changes. Check your connection and try again.'
                      : 'Preparing offline changes…',
                  onRetry: () =>
                      ref.invalidate(syncInitializerProvider(userId)),
                ),
              ],
            );
          }
          if (id == null) {
            return _CategoryEditor(repository: repository, userId: userId);
          }
          return StreamBuilder<Category?>(
            stream: repository.watchCategory(userId, id!),
            builder: (context, snapshot) {
              if (snapshot.hasError ||
                  (snapshot.connectionState != ConnectionState.waiting &&
                      (snapshot.data == null ||
                          snapshot.data!.isSystem ||
                          snapshot.data!.userId != userId ||
                          snapshot.data!.status != 'active'))) {
                return const FinanceScreen(
                  children: [
                    FinanceStateView(
                      state: FinanceViewState.error,
                      message: 'This category cannot be edited.',
                    ),
                  ],
                );
              }
              if (snapshot.data == null) {
                return const FinanceScreen(
                  children: [
                    FinanceStateView(
                      state: FinanceViewState.loading,
                      message: 'Loading category…',
                    ),
                  ],
                );
              }
              return _CategoryEditor(
                key: ValueKey('edit-category:$userId:$id'),
                repository: repository,
                userId: userId,
                category: snapshot.data,
              );
            },
          );
        },
        loading: () {
          ref.watch(syncInitializerProvider(userId));
          return const FinanceScreen(
            children: [
              FinanceStateView(
                state: FinanceViewState.loading,
                message: 'Preparing offline changes…',
              ),
            ],
          );
        },
        error: (_, _) => const FinanceScreen(
          children: [
            FinanceStateView(
              state: FinanceViewState.error,
              message: 'Could not prepare offline changes.',
            ),
          ],
        ),
      );
    },
  );
}

class _CategoryEditor extends StatefulWidget {
  const _CategoryEditor({
    super.key,
    required this.repository,
    required this.userId,
    this.category,
  });
  final CategoriesRepository repository;
  final String userId;
  final Category? category;

  @override
  State<_CategoryEditor> createState() => _CategoryEditorState();
}

class _CategoryEditorState extends State<_CategoryEditor> {
  final _form = GlobalKey<FormState>();
  late final TextEditingController _name;
  late final TextEditingController _description;
  late String _type;
  late String? _icon;
  bool _submitting = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _name = TextEditingController(text: widget.category?.name);
    _description = TextEditingController(text: widget.category?.description);
    _type = widget.category?.categoryType ?? 'expense';
    _icon = widget.category?.icon;
  }

  @override
  void dispose() {
    _name.dispose();
    _description.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_submitting || !_form.currentState!.validate()) return;
    setState(() {
      _submitting = true;
      _error = null;
    });
    try {
      if (widget.repository.authenticatedUserId() != widget.userId) {
        throw StateError('Your session changed. Reopen this form.');
      }
      final id = widget.category == null
          ? await widget.repository.mutations.createCategory(
              name: _name.text.trim(),
              categoryType: _type,
              icon: _icon,
              description: _description.text.trim(),
            )
          : await () async {
              await widget.repository.mutations.updateCategory(
                id: widget.category!.id,
                name: _name.text.trim(),
                categoryType: _type,
                icon: _icon,
                description: _description.text.trim(),
              );
              return widget.category!.id;
            }();
      if (mounted && widget.repository.authenticatedUserId() == widget.userId) {
        if (widget.category == null) {
          context.replace(_categoryPath(id));
        } else {
          context.pop();
        }
      }
    } catch (error) {
      if (mounted) setState(() => _error = _categoryError(error));
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final editing = widget.category != null;
    return FinanceScreen(
      children: [
        _Heading(
          title: editing ? 'Edit category' : 'Add category',
          backTo: editing ? _categoryPath(widget.category!.id) : '/categories',
        ),
        const SizedBox(height: SanieSpace.lg),
        Form(
          key: _form,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              TextFormField(
                key: const Key('category-name'),
                controller: _name,
                enabled: !_submitting,
                maxLength: 100,
                textCapitalization: TextCapitalization.words,
                decoration: const InputDecoration(labelText: 'Category name'),
                validator: (value) => value == null || value.trim().isEmpty
                    ? 'Enter a category name.'
                    : null,
              ),
              const SizedBox(height: SanieSpace.md),
              DropdownButtonFormField<String>(
                key: const Key('category-type'),
                initialValue: _type,
                decoration: const InputDecoration(labelText: 'Type'),
                items: const [
                  DropdownMenuItem(value: 'expense', child: Text('Expense')),
                  DropdownMenuItem(value: 'income', child: Text('Income')),
                ],
                onChanged: editing || _submitting
                    ? null
                    : (value) {
                        if (value != null) setState(() => _type = value);
                      },
              ),
              if (editing) ...[
                const SizedBox(height: SanieSpace.xs),
                Text(
                  'Type is fixed after creation to protect financial history.',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ],
              const SizedBox(height: SanieSpace.md),
              _IconSelector(
                value: _icon,
                enabled: !_submitting,
                onChanged: (value) => setState(() => _icon = value),
              ),
              const SizedBox(height: SanieSpace.md),
              TextFormField(
                key: const Key('category-description'),
                controller: _description,
                enabled: !_submitting,
                maxLength: 1000,
                minLines: 2,
                maxLines: 4,
                decoration: const InputDecoration(
                  labelText: 'Description (optional)',
                ),
              ),
              if (_error != null) ...[
                const SizedBox(height: SanieSpace.sm),
                Text(
                  _error!,
                  key: const Key('category-form-error'),
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ],
              const SizedBox(height: SanieSpace.lg),
              FinancePrimaryButton(
                label: editing ? 'Save category' : 'Add category',
                onPressed: _submitting ? null : _submit,
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class SubcategoryFormPage extends ConsumerWidget {
  const SubcategoryFormPage({super.key, required this.parentId, this.id});
  final String parentId;
  final String? id;

  @override
  Widget build(BuildContext context, WidgetRef ref) => _authView(
    ref.watch(accountUserProvider),
    (userId) {
      final repository = ref.watch(categoriesRepositoryProvider);
      if (repository.authenticatedUserId() != userId) return _sessionChanged();
      final sync = ref.watch(userSyncStateProvider(userId));
      if (sync.value == null) {
        ref.watch(syncInitializerProvider(userId));
        return FinanceScreen(
          children: [
            _Heading(
              title: id == null ? 'Add subcategory' : 'Edit subcategory',
              backTo: _categoryPath(parentId),
            ),
            const SizedBox(height: SanieSpace.lg),
            FinanceStateView(
              state: sync.hasError
                  ? FinanceViewState.error
                  : FinanceViewState.loading,
              message: sync.hasError
                  ? 'Could not prepare offline changes.'
                  : 'Preparing offline changes…',
            ),
          ],
        );
      }
      return StreamBuilder<Category?>(
        stream: repository.watchCategory(userId, parentId),
        builder: (context, parentSnapshot) {
          final parent = parentSnapshot.data;
          if (parentSnapshot.hasError ||
              (parentSnapshot.connectionState != ConnectionState.waiting &&
                  (parent == null || parent.status != 'active'))) {
            return const FinanceScreen(
              children: [
                FinanceStateView(
                  state: FinanceViewState.error,
                  message: 'Parent category is unavailable.',
                ),
              ],
            );
          }
          if (parent == null) {
            return const FinanceScreen(
              children: [
                FinanceStateView(
                  state: FinanceViewState.loading,
                  message: 'Loading category…',
                ),
              ],
            );
          }
          if (id == null) {
            return _SubcategoryEditor(
              repository: repository,
              userId: userId,
              parent: parent,
            );
          }
          return StreamBuilder<Subcategory?>(
            stream: repository.watchSubcategory(userId, parentId, id!),
            builder: (context, snapshot) {
              final sub = snapshot.data;
              if (snapshot.hasError ||
                  (snapshot.connectionState != ConnectionState.waiting &&
                      (sub == null ||
                          sub.userId != userId ||
                          sub.status != 'active'))) {
                return const FinanceScreen(
                  children: [
                    FinanceStateView(
                      state: FinanceViewState.error,
                      message: 'This subcategory cannot be edited.',
                    ),
                  ],
                );
              }
              if (sub == null) {
                return const FinanceScreen(
                  children: [
                    FinanceStateView(
                      state: FinanceViewState.loading,
                      message: 'Loading subcategory…',
                    ),
                  ],
                );
              }
              return _SubcategoryEditor(
                key: ValueKey('edit-subcategory:$userId:$id'),
                repository: repository,
                userId: userId,
                parent: parent,
                subcategory: sub,
              );
            },
          );
        },
      );
    },
  );
}

class _SubcategoryEditor extends StatefulWidget {
  const _SubcategoryEditor({
    super.key,
    required this.repository,
    required this.userId,
    required this.parent,
    this.subcategory,
  });
  final CategoriesRepository repository;
  final String userId;
  final Category parent;
  final Subcategory? subcategory;

  @override
  State<_SubcategoryEditor> createState() => _SubcategoryEditorState();
}

class _SubcategoryEditorState extends State<_SubcategoryEditor> {
  final _form = GlobalKey<FormState>();
  late final TextEditingController _name;
  late final TextEditingController _description;
  late String? _icon;
  bool _submitting = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _name = TextEditingController(text: widget.subcategory?.name);
    _description = TextEditingController(text: widget.subcategory?.description);
    _icon = widget.subcategory?.icon;
  }

  @override
  void dispose() {
    _name.dispose();
    _description.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_submitting || !_form.currentState!.validate()) return;
    setState(() {
      _submitting = true;
      _error = null;
    });
    try {
      if (widget.repository.authenticatedUserId() != widget.userId) {
        throw StateError('Your session changed. Reopen this form.');
      }
      if (widget.subcategory == null) {
        await widget.repository.mutations.createSubcategory(
          categoryId: widget.parent.id,
          name: _name.text.trim(),
          icon: _icon,
          description: _description.text.trim(),
        );
      } else {
        await widget.repository.mutations.updateSubcategory(
          id: widget.subcategory!.id,
          name: _name.text.trim(),
          icon: _icon,
          description: _description.text.trim(),
        );
      }
      if (mounted && widget.repository.authenticatedUserId() == widget.userId) {
        context.pop();
      }
    } catch (error) {
      if (mounted) setState(() => _error = _categoryError(error));
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final editing = widget.subcategory != null;
    return FinanceScreen(
      children: [
        _Heading(
          title: editing ? 'Edit subcategory' : 'Add subcategory',
          backTo: _categoryPath(widget.parent.id),
        ),
        const SizedBox(height: SanieSpace.lg),
        FinanceCard(
          child: Text(
            'Category: ${widget.parent.name}',
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
          ),
        ),
        const SizedBox(height: SanieSpace.md),
        Form(
          key: _form,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              TextFormField(
                key: const Key('subcategory-name'),
                controller: _name,
                enabled: !_submitting,
                maxLength: 100,
                textCapitalization: TextCapitalization.words,
                decoration: const InputDecoration(
                  labelText: 'Subcategory name',
                ),
                validator: (value) => value == null || value.trim().isEmpty
                    ? 'Enter a subcategory name.'
                    : null,
              ),
              const SizedBox(height: SanieSpace.md),
              _IconSelector(
                value: _icon,
                enabled: !_submitting,
                onChanged: (value) => setState(() => _icon = value),
              ),
              const SizedBox(height: SanieSpace.md),
              TextFormField(
                key: const Key('subcategory-description'),
                controller: _description,
                enabled: !_submitting,
                maxLength: 1000,
                minLines: 2,
                maxLines: 4,
                decoration: const InputDecoration(
                  labelText: 'Description (optional)',
                ),
              ),
              if (_error != null) ...[
                const SizedBox(height: SanieSpace.sm),
                Text(
                  _error!,
                  key: const Key('subcategory-form-error'),
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ],
              const SizedBox(height: SanieSpace.lg),
              FinancePrimaryButton(
                label: editing ? 'Save subcategory' : 'Add subcategory',
                onPressed: _submitting ? null : _submit,
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _IconSelector extends StatelessWidget {
  const _IconSelector({
    required this.value,
    required this.enabled,
    required this.onChanged,
  });
  final String? value;
  final bool enabled;
  final ValueChanged<String?> onChanged;

  @override
  Widget build(BuildContext context) {
    final options = <String, String>{
      if (value != null && !_iconChoices.containsKey(value)) value!: value!,
      ..._iconChoices,
    };
    return DropdownButtonFormField<String>(
      key: const Key('category-icon'),
      initialValue: value ?? '',
      isExpanded: true,
      decoration: const InputDecoration(labelText: 'Icon (optional)'),
      items: [
        const DropdownMenuItem(value: '', child: Text('Default icon')),
        for (final entry in options.entries)
          DropdownMenuItem(
            value: entry.key,
            child: Row(
              children: [
                Icon(_iconFor(entry.key), size: 20),
                const SizedBox(width: SanieSpace.sm),
                Flexible(
                  child: Text(entry.value, overflow: TextOverflow.ellipsis),
                ),
              ],
            ),
          ),
      ],
      onChanged: enabled
          ? (value) => onChanged(value == '' ? null : value)
          : null,
    );
  }
}

String _categoryError(Object error) {
  if (error is FormatException) return error.message;
  if (error is StateError) return error.message;
  return 'Could not save this change on your device. Please try again.';
}
