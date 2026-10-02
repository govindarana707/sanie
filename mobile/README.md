# SanIE mobile

Minimal Flutter foundation for SanIE 2.0.

Provide the dedicated non-production Supabase client configuration at launch.
Do not put credentials in source files or commit local launch configurations.

```powershell
flutter run --dart-define=SUPABASE_URL=https://PROJECT_REF.supabase.co `
  --dart-define=SUPABASE_PUBLISHABLE_KEY=YOUR_PUBLISHABLE_OR_ANON_KEY
```

Only a Supabase publishable/anon key belongs in the client. Never use a
service-role key, database password, personal access token, or JWT secret.
