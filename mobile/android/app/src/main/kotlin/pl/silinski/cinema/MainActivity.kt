package pl.silinski.cinema

import io.flutter.embedding.android.FlutterFragmentActivity

// flutter_stripe wymaga aktywności opartej na FragmentActivity (PaymentSheet
// pokazuje się jako fragment) oraz motywu dziedziczącego z Theme.AppCompat —
// patrz res/values/styles.xml. Bez tego arkusz płatności wysypuje się dopiero
// w czasie działania aplikacji, przy pierwszej próbie zapłaty.
class MainActivity : FlutterFragmentActivity()
