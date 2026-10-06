<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('judul', 'Khandaq') · Khandaq</title>
<link rel="icon" href="{{ asset('img/logo.png') }}">
<link rel="stylesheet" href="{{ asset('css/khandaq.css') }}?v={{ @filemtime(public_path('css/khandaq.css')) }}">
</head>
<body>
@yield('isi')
</body>
</html>
