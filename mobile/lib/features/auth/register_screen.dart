// Rejestracja. Reguły hasła pilnuje serwer (min. 8 znaków, litery i cyfry),
// a my pokazujemy jego komunikaty przy polach — jeden zestaw reguł zamiast
// dwóch, które mogłyby się rozjechać.

import 'package:cinema/core/api_error.dart';
import 'package:cinema/router.dart';
import 'package:cinema/state/auth.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

class RegisterScreen extends ConsumerStatefulWidget {
  const RegisterScreen({super.key});

  @override
  ConsumerState<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends ConsumerState<RegisterScreen> {
  final GlobalKey<FormState> _form = GlobalKey<FormState>();
  final TextEditingController _name = TextEditingController();
  final TextEditingController _email = TextEditingController();
  final TextEditingController _password = TextEditingController();
  final TextEditingController _confirmation = TextEditingController();

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _password.dispose();
    _confirmation.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!(_form.currentState?.validate() ?? false)) {
      return;
    }
    final bool ok = await ref
        .read(authProvider.notifier)
        .register(
          name: _name.text.trim(),
          email: _email.text.trim(),
          password: _password.text,
          passwordConfirmation: _confirmation.text,
        );
    if (ok && mounted) {
      context.go(Routes.home);
    }
  }

  @override
  Widget build(BuildContext context) {
    final AuthState auth = ref.watch(authProvider);
    final ApiError? error = auth.error;

    return Scaffold(
      appBar: AppBar(title: const Text('Rejestracja')),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Form(
          key: _form,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              if (error != null && error.code != ApiError.validationFailed)
                Padding(
                  padding: const EdgeInsets.only(bottom: 16),
                  child: Text(
                    error.message,
                    style: TextStyle(
                      color: Theme.of(context).colorScheme.error,
                    ),
                  ),
                ),
              TextFormField(
                controller: _name,
                textInputAction: TextInputAction.next,
                decoration: InputDecoration(
                  labelText: 'Imię',
                  errorText: error?.fieldError('name'),
                ),
                validator: (String? value) =>
                    (value == null || value.trim().length < 2)
                    ? 'Podaj imię.'
                    : null,
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _email,
                keyboardType: TextInputType.emailAddress,
                textInputAction: TextInputAction.next,
                decoration: InputDecoration(
                  labelText: 'Adres e-mail',
                  errorText: error?.fieldError('email'),
                ),
                validator: (String? value) =>
                    (value == null || !value.contains('@'))
                    ? 'Podaj adres e-mail.'
                    : null,
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _password,
                obscureText: true,
                textInputAction: TextInputAction.next,
                decoration: InputDecoration(
                  labelText: 'Hasło',
                  helperText: 'Co najmniej 8 znaków, litery i cyfry.',
                  errorText: error?.fieldError('password'),
                ),
                validator: (String? value) =>
                    (value == null || value.length < 8)
                    ? 'Hasło musi mieć co najmniej 8 znaków.'
                    : null,
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _confirmation,
                obscureText: true,
                textInputAction: TextInputAction.done,
                decoration: const InputDecoration(labelText: 'Powtórz hasło'),
                validator: (String? value) => value != _password.text
                    ? 'Hasła muszą być takie same.'
                    : null,
                onFieldSubmitted: (_) => _submit(),
              ),
              const SizedBox(height: 24),
              FilledButton(
                onPressed: auth.busy ? null : _submit,
                child: Text(auth.busy ? 'Zakładanie konta…' : 'Załóż konto'),
              ),
              TextButton(
                onPressed: auth.busy ? null : () => context.go(Routes.login),
                child: const Text('Mam już konto'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
