<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>@yield('title_prefix', config('adminlte.title_prefix', ''))
        @yield('title', config('adminlte.title', 'AdminLTE 2'))
        @yield('title_postfix', config('adminlte.title_postfix', ''))</title>
    <!-- Tell the browser to be responsive to screen width -->
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
    <link rel="stylesheet" href="{{asset('/css/liman.css')}}">

</head>
<body class="hold-transition @yield('body_class')">
<script src="{{asset('js/libraries.js')}}"></script>
<script src="{{asset('/js/liman.js')}}"></script>
<script>
    $(document).ready(function() {
        $('table').not('.notDataTable').DataTable({
            autoFill : true,
            bFilter: true,
            destroy: true,
            "language" : {
                url : "{{asset('turkce.json')}}"
            }
        });
    } );
</script>

@yield('body')
</body>
</html>
