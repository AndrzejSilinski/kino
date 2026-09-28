// Logowanie. Widget zbiera dane i woła kontroler; cała logika (żądanie,
// token, stan) siedzi w AuthController.

import 'package:cinema/core/api_error.dart';
import 'package:cinema/router.dart';
import 'package:cinema/state/auth.dart';
import 'package:cinema/state/providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final GlobalKey<FormState> _form = GlobalKey<FormState>();
  final TextEditingController _email = TextEditingController();
  final TextEditingController _password = TextEditingController();

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!(_form.currentState?.validate() ?? false)) {
      return;
    }
    final bool ok = await ref
        .read(authProvider.notifier)
        .login(email: _email.text.trim(), password: _password.text);
    if (ok && mounted) {
      context.go(Routes.home);
    }
  }

  @override
  Widget build(BuildContext context) {
    final AuthState auth = ref.watch(authProvider);
    final ApiError? error = auth.error;

    return Scaffold(
      appBar: AppBar(title: const Text('Logowanie')),
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
                controller: _email,
                autofillHints: const <String>[AutofillHints.email],
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
                autofillHints: const <String>[AutofillHints.password],
                obscureText: true,
                textInputAction: TextInputAction.done,
                decoration: InputDecoration(
                  labelText: 'Hasło',
                  errorText: error?.fieldError('password'),
                ),
                validator: (String? value) =>
                    (value == null || value.isEmpty) ? 'Podaj hasło.' : null,
                onFieldSubmitted: (_) => _submit(),
              ),
              const SizedBox(height: 24),
              FilledButton(
                onPressed: auth.busy ? null : _submit,
                child: Text(auth.busy ? 'Logowanie…' : 'Zaloguj się'),
              ),
              TextButton(
                onPressed: auth.busy ? null : () => context.go(Routes.register),
                child: const Text('Nie mam jeszcze konta'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
