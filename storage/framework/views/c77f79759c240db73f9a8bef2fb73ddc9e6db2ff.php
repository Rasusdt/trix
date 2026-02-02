
<?php if(auth()->guard()->check()): ?>
<!doctype html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale())); ?>">
    <head>
        <meta charset="UTF-8" />
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=Edge,chrome=1" />
        <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
        <link rel="shortcut icon" href="/image/icon.png?v=<?php echo e(time()); ?>">
        <title><?php echo e($settings->title); ?></title>
        <meta name="description" content="<?php echo e($settings->description); ?>">
        <meta name="keywords" content="<?php echo e($settings->keywords); ?>">
        <link rel="preconnect" href="https://fonts.gstatic.com">
        <link href="https://fonts.googleapis.com/css2?family=Rubik:wght@300;400;500;600&display=swap" rel="stylesheet">

        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    </head>
    <body>
        <noscript>You need to enable JavaScript to run this app.</noscript>
        <div id="root"></div>
        <div class="errors" style="display: none"><?php echo e(session('error')); ?></div>
    </body>
    <?php
        if(isset($_GET['invite'])) {
            session_start();
            $_SESSION['ref'] = $_GET['invite'];
        }
    ?>
    <script src="/js/app.js?v=<?php echo e($settings->file_version); ?>"></script>
    <link rel="stylesheet" href="<?php echo e(asset('assets/app.css')); ?>?v=<?php echo e($settings->file_version); ?>">
    <link rel="stylesheet" class="theme" href="#">
    <link rel="stylesheet" href="<?php echo e(asset('assets/wheel.css')); ?>?v=<?php echo e($settings->file_version); ?>">
    <script src="/js/theme.js?v=<?php echo e($settings->file_version); ?>"></script>
</html>
<?php else: ?>
<?php echo $__env->make('confirm', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?>
<?php endif; ?><?php /**PATH D:\OSPanel\domains\localhost\resources\views/app.blade.php ENDPATH**/ ?>