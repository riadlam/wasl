@component('auth.layout', ['title' => 'Create shop'])
    <div
        id="auth-root"
        data-mode="register"
        data-action="{{ route('register') }}"
        data-csrf="{{ csrf_token() }}"
        data-login="{{ route('login') }}"
        data-register="{{ route('register') }}"
        data-errors='@json($errors->getMessages())'
        data-old='@json(collect(old())->only(["name", "shop_name", "email"]))'
    ></div>
@endcomponent
