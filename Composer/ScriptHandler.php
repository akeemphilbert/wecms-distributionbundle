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
            if (!$event->getIO()->askConfirmation('Would you like to install the Admin bundle? [Y/n] ', true)) {
                return;
            }
        }
    
        $event->getIO()->write('Installing the Admin bundle.');
        $fs = new Filesystem();
    
        $appDir = $options['symfony-app-dir'];
    
        $kernelFile = $appDir.'/AppKernel.php';
        $ref = 'new Sensio\Bundle\FrameworkExtraBundle\SensioFrameworkExtraBundle(),';//TODO replace with custom profiler here
        $bundleDeclaration = "new WeCMS\\AdminBundle\\WeCMSAdminBundle(),\n            ";
        $bundleDeclaration .= "new FOS\UserBundle\FOSUserBundle(),\n            ";
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
        $username = $event->getIO()->askAndValidate("Please enter the username for the super admin account e.g. 'superadmin': ",function($response) {
            if (!empty($response)) {
                return $response;
            } else {
                throw new \RuntimeException("Please enter a valid username");
            }
        });
        $password = $event->getIO()->askAndHideAnswer("Please enter the password for the super admin account e.g. 'password': ");
        self::patchAdminBundleConfiguration($appDir, $fs, $prefix,$username,$password);
    }
    
    private static function patchAdminBundleConfiguration($appDir, Filesystem $fs, $prefix = "",$username,$password)
    {
        $routingFile = $appDir.'/config/routing.yml';
        $securityFile = $appDir.'/config/security.yml';
        $configFile = $appDir.'/config/config.yml';
    
        $routingData = file_get_contents($routingFile).<<<EOF
_we_admin:
    resource: "@WeCMSAdminBundle/Controller/"
    type: annotation
    prefix: /$prefix
fos_user_security:
    resource: "@FOSUserBundle/Resources/config/routing/security.xml"
    prefix: /$prefix/users
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
        chain_provider:
            chain:
                providers: [in_memory, fos_userbundle]
        fos_userbundle:
            id: fos_user.user_provider.username
        in_memory:
            memory:
                users:
                    $username: { password: $password, roles: [ 'ROLE_SUPER_ADMIN' ] }
    
    # the main part of the security, where you can set up firewalls
    # for specific sections of your app
    firewalls:
        # disables authentication for assets and the profiler, adapt it according to your needs
        dev:
            pattern:  ^/(_(profiler|wdt)|css|images|js)/
            security: false
        # secures part of the application
        admin_area:
            pattern:    ^/$prefix/
            form_login:
                provider: fos_userbundle
                csrf_provider: form.csrf_provider
                login_path:     fos_user_security_login
                use_forward:    false
                check_path:     fos_user_security_check
                failure_path:   null
            logout: true
            anonymous: true
            #http_basic:
            #    realm: "Secured Demo Area"
    
    # with these settings you can restrict or allow access for different parts
    # of your application based on roles, ip, host or methods
    # http://symfony.com/doc/current/book/security.html#security-book-access-control-matching-options
    access_control:
        - { path: ^/$prefix/users/login$, role: IS_AUTHENTICATED_ANONYMOUSLY }
        - { path: ^/$prefix/, role: ROLE_ADMIN }
        #- { path: ^/login, roles: IS_AUTHENTICATED_ANONYMOUSLY, requires_channel: https }
EOF;
    
        $fs->dumpFile($securityFile, $securityData);
        
        $configData = file_get_contents($configFile).<<<EOF
#Userbundle Configuration
fos_user:
    db_driver: orm
    firewall_name: admin_area
    user_class: WeCMS\UserBundle\Entity\User
    group:
        group_class: WeCMS\UserBundle\Entity\Group
EOF;
        $fs->dumpFile($configFile, $configData);
    }
    
    public static function generateDatabase(CommandEvent $event)
    {
        $options = self::getOptions($event);
        $consoleDir = self::getConsoleDir($event, 'generate database schema');
        
        if (!$event->getIO()->askConfirmation('Create database? [N/y] ', false)) {
            static::executeCommand($event, $consoleDir, 'doctrine:schema:create', $options['process-timeout']);
        }
        
        self::updateDatabase($event);
    }
    
    
    public static function updateDatabase(CommandEvent $event)
    {
        $options = self::getOptions($event);
        $consoleDir = self::getConsoleDir($event, 'update database schema');
        static::executeCommand($event, $consoleDir, 'doctrine:schema:update --force', $options['process-timeout']);
        
    }
}