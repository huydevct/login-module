@extends('login::layouts.master')

@section('guest', true)

@section('content')
    <div class="bg-light min-vh-100 d-flex flex-row align-items-center">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-4">
                    <div class="card-group d-block d-md-flex row">
                        <div class="card col-md-7 p-4 mb-0">
                            <div class="card-body">
                                <h1>Login</h1>
                                <p class="text-medium-emphasis">Sign In to your account</p>
                                @if (session()->has('error'))
                                    <div class="alert alert-danger" role="alert">
                                        {{ session('error') }}
                                    </div>
                                @endif
                                @foreach ($errors->all() as $message)
                                    <div class="alert alert-danger" role="alert">
                                        {{ $message }}
                                    </div>
                                @endforeach
                                <form action="{{ route('login.post') }}" method="POST">
                                    @csrf
                                    <div class="input-group mb-3">
                                        <span class="input-group-text">
                                            <x-login::vendors.icon name="cil-user" class="icon" />
                                        </span>
                                        <input name="email" class="form-control" type="email" placeholder="Username" value="{{ old('email') }}" required>
                                    </div>
                                    <div class="input-group mb-4">
                                        <span class="input-group-text">
                                            <x-login::vendors.icon name="cil-lock-locked" class="icon" />
                                        </span>
                                        <input name="password" class="form-control" type="password" placeholder="Password" required>
                                    </div>
                                    <div class="row">
                                        <div class="col-6">
                                            <button class="btn btn-primary px-4" type="submit">Login</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
