@component('auth.layout', ['title' => 'Log in'])
    <div
        id="auth-root"
        data-mode="login"
        data-action="{{ route('login') }}"
        data-csrf="{{ csrf_token() }}"
        data-login="{{ route('login') }}"
        data-register="{{ route('register') }}"
        data-errors='@json($errors->getMessages())'
        data-old='@json(collect(old())->only(["email"]))'
    ></div>
@endcomponent
