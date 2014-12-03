<?php

namespace WeCMS\DistributionBundle\Composer;
use Sensio\Bundle\DistributionBundle\Composer\ScriptHandler as SensioScriptHandler;
use Composer\Script\CommandEvent;
use Symfony\Component\Filesystem\Filesystem;

class ScriptHandler extends SensioScriptHandler
{
    public static function installAdminBundle(CommandEvent $event)
    {
        $rootDir = getcwd();
        $options = self::getOptions($event);
    
        if (
                !file_exists($rootDir.'/vendor/wepala/wecms-adminbundle/WeCMS/AdminBundle') ||
                !file_exists($rootDir.'/vendor/wepala/wecms-userbundle/WeCMS/UserBundle')
        ) {
            return;
        }
    
        if (!getenv('WECMS_FORCE_ADMIN')) {
            if (!$event->getIO()->askConfirmation('Would you like to install the Admin bundle? [y/N] ', false)) {
                return;
            }
        }
    
        $event->getIO()->write('Installing the Admin bundle.');
        $fs = new Filesystem();
    
        $appDir = $options['symfony-app-dir'];
    
        $kernelFile = $appDir.'/AppKernel.php';
        $ref = 'new Sensio\Bundle\FrameworkExtraBundle\SensioFrameworkExtraBundle(),';//TODO replace with custom profiler here
        $bundleDeclaration = "new WeCMS\\AdminBundle\\WeCMSAdminBundle(),\n            ";
        $bundleDeclaration .= "new WeCMS\\UserBundle\\WeCMSUserBundle(),";
        $content = file_get_contents($kernelFile);
    
        if (false === strpos($content, $bundleDeclaration)) {
            $updatedContent = str_replace($ref, $bundleDeclaration."\n            ".$ref, $content);
            if ($content === $updatedContent) {
                throw new \RuntimeException('Unable to patch %s.', $kernelFile);
            }
            $fs->dumpFile($kernelFile, $updatedContent);
        }
        $prefix = ltrim($event->getIO()->ask("Please enter the prefix used to access the admin section e.g. 'admin': "),"/");
        self::patchAdminBundleConfiguration($appDir, $fs, $prefix);
    }
    
    private static function patchAdminBundleConfiguration($appDir, Filesystem $fs, $prefix = "")
    {
        $routingFile = $appDir.'/config/routing.yml';
        $securityFile = $appDir.'/config/security.yml';
    
        $routingData = file_get_contents($routingFile).<<<EOF
_we_admin:
    resource: "@WeCMSAdminBundle/Controller/"
        type:     annotation
      prefix:   /$prefix
_we_users:
    resource: "@WeCMSUserBundle/Controller/"
        type:     annotation
      prefix:   /$prefix
EOF;
        $fs->dumpFile($routingFile, $routingData);
    
        $securityData = <<<EOF
# you can read more about security in the related section of the documentation
# http://symfony.com/doc/current/book/security.html
security:
    # http://symfony.com/doc/current/book/security.html#encoding-the-user-s-password
    encoders:
        Symfony\Component\Security\Core\User\User: plaintext
    
    # http://symfony.com/doc/current/book/security.html#hierarchical-roles
    role_hierarchy:
        ROLE_ADMIN:       ROLE_USER
        ROLE_SUPER_ADMIN: [ROLE_USER, ROLE_ADMIN, ROLE_ALLOWED_TO_SWITCH]
    
    # http://symfony.com/doc/current/book/security.html#where-do-users-come-from-user-providers
    providers:
        in_memory:
            memory:
                users:
                    user:  { password: userpass, roles: [ 'ROLE_USER' ] }
                    admin: { password: adminpass, roles: [ 'ROLE_ADMIN' ] }
    
    # the main part of the security, where you can set up firewalls
    # for specific sections of your app
    firewalls:
        # disables authentication for assets and the profiler, adapt it according to your needs
        dev:
            pattern:  ^/(_(profiler|wdt)|css|images|js)/
            security: false
        # the login page has to be accessible for everybody
        admin_login:
            pattern:  ^/$prefix/users/login$
            security: false
    
        # secures part of the application
        admin_area:
            pattern:    ^/$prefix/
            form_login:
                check_path: _we_admin_security_check
                login_path: _we_admin_login
            logout:
                path:   _we_admin_logout
                target: _we_admin
            #anonymous: ~
            #http_basic:
            #    realm: "Secured Demo Area"
    
    # with these settings you can restrict or allow access for different parts
    # of your application based on roles, ip, host or methods
    # http://symfony.com/doc/current/book/security.html#security-book-access-control-matching-options
    access_control:
        #- { path: ^/login, roles: IS_AUTHENTICATED_ANONYMOUSLY, requires_channel: https }
EOF;
    
        $fs->dumpFile($securityFile, $securityData);
    }
}