<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit26859d7156a0cc489f96f323fa50c4e2
{
    public static $prefixLengthsPsr4 = array (
        'B' => 
        array (
            'Btcpayserver\\BtcpayVirtuemartPlugin\\' => 36,
            'BTCPayServer\\' => 13,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Btcpayserver\\BtcpayVirtuemartPlugin\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src',
        ),
        'BTCPayServer\\' => 
        array (
            0 => __DIR__ . '/..' . '/btcpayserver/btcpayserver-greenfield-php/src',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit26859d7156a0cc489f96f323fa50c4e2::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit26859d7156a0cc489f96f323fa50c4e2::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInit26859d7156a0cc489f96f323fa50c4e2::$classMap;

        }, null, ClassLoader::class);
    }
}
