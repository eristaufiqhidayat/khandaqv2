@extends('layouts.dasar')
@section('judul', 'Belum ada akses')
@section('isi')
<div class="masuk"><div class="kotak">
  <div class="logo"><img src="{{ asset('img/logo.png') }}" alt=""><h1>Belum ada akses</h1>
  <small>Akun {{ auth()->user()->name }} belum diberi menu apa pun. Minta Admin mengatur peran atau izin Anda di menu Hak akses.</small></div>
  <form method="post" action="{{ route('logout') }}">@csrf<button class="btn">Keluar</button></form>
</div></div>
@endsection
