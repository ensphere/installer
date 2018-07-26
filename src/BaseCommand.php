<?php

namespace Ensphere\Installer;

use Symfony\Component\Process\Process;
use Symfony\Component\Console\Command\Command;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use RuntimeException;
use STDclass;

abstract class BaseCommand extends Command
{

    /**
     * @var string
     */
    protected $version = '1.1.0';

    /**
     * @var
     */
    protected $dbdetails;

    /**
     * @var string
     */
    protected $modulesUrl = 'https://modules.testing.pm/';

    /**
     * @var array
     */
    protected $availableModules = [];

    /**
     * @var array
     */
    protected $frontRequired = [
        'purposemedia/front-banners',
        'purposemedia/front-blog',
        'purposemedia/front-contact',
        'purposemedia/front-container',
        'purposemedia/front-customers',
        'purposemedia/front-delivery',
        'purposemedia/front-faqs',
        'purposemedia/front-feeds',
        'purposemedia/front-form-builder',
        'purposemedia/front-mailing-list',
        'purposemedia/front-media-manager',
        'purposemedia/front-menu-manager',
        'purposemedia/front-pages',
        'purposemedia/front-post-types',
        'purposemedia/front-redirects',
        'purposemedia/front-search',
        'purposemedia/front-shop',
        'purposemedia/front-sitemap',
        'purposemedia/front-sites',
        'purposemedia/front-snippets',
        'purposemedia/front-social-feeds',
        'purposemedia/front-ads-manager',
        'purposemedia/front-multibuy',
    ];

    /**
     * @var array
     */
    protected $backRequired = [
        'purposemedia/sites',
        'purposemedia/users',
        'purposemedia/admin-ads-manager',
        'purposemedia/admin-banners',
        'purposemedia/admin-blog',
        'purposemedia/admin-contact',
        'purposemedia/admin-customers',
        'purposemedia/admin-delivery',
        'purposemedia/admin-faqs',
        'purposemedia/admin-feeds',
        'purposemedia/admin-form-builder',
        'purposemedia/admin-mailing-list',
        'purposemedia/admin-media-manager',
        'purposemedia/admin-menu-manager',
        'purposemedia/admin-multibuy',
        'purposemedia/admin-pages',
        'purposemedia/admin-post-types',
        'purposemedia/admin-redirects',
        'purposemedia/admin-search-modules',
        'purposemedia/admin-settings',
        'purposemedia/admin-shop',
        'purposemedia/admin-snippets',
        'purposemedia/admin-social-feeds',
        'purposemedia/authentication'
    ];

    /**
     * @return void
     */
    protected function connect()
    {
        $this->capsule = new Capsule;
        $this->capsule->addConnection([
            'driver'    => 'mysql',
            'host'      => $this->dbdetails->host,
            'database'  => $this->dbdetails->name,
            'username'  => $this->dbdetails->user,
            'password'  => $this->dbdetails->pass,
            'charset'   => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix'    => '',
        ]);
        $this->capsule->setEventDispatcher( new Dispatcher( new Container ) );
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
    }

    /**
     * @param $output
     * @param $path
     * @param $command
     */
    protected function runCommand( $output, $path, $command )
    {
        $home = getEnv( 'HOME' );
        $envVars = [ 'HOME' => $home, 'PATH' => getEnv('PATH') . ":{$home}/.composer/vendor/ensphere/installer/bin/" ];

        $process = new Process( $command, $path, $envVars );
        if ( '\\' !== DIRECTORY_SEPARATOR && file_exists( '/dev/tty' ) && is_readable( '/dev/tty' ) ) {
            $process->setTty( true );
        }
        $process->run( function ( $type, $line ) use ( $output ) {
            $output->write( $line );
        });
    }

    /**
     * @return STDclass
     */
    protected function getDatabaseDetails( $input, $output )
    {
        if( is_null( $this->dbdetails ) ) {
            $output->writeln( '<error>SETUP YOUR DATABASE BEFORE ENTERING DETAILS!</error>' );
            $this->dbdetails = new STDclass;
            $helper = $this->getHelper( 'question' );

            $question = new ConfirmationQuestion( 'Are you using MAMP [y/n]', false );
            $usingMAMP = $helper->ask( $input, $output, $question );

            $question = new Question( 'What is your database host: ', false );
            if( ! $databaseHost = $helper->ask( $input, $output, $question ) ) {
                throw new RuntimeException( 'You must supply a database host to complete the installation process.' );
            }
            $question = new Question( 'What is your database port (3306): ', 3306 );
            if( ! $databasePort = $helper->ask( $input, $output, $question ) ) {
                throw new RuntimeException( 'You must supply a database port to complete the installation process.' );
            }
            $question = new Question( 'What is your database name: ', false );
            if( ! $databaseName = $helper->ask( $input, $output, $question ) ) {
                throw new RuntimeException( 'You must supply a database name to complete the installation process.' );
            }
            $question = new Question( 'What is your database user: ', false );
            if( ! $databaseUser = $helper->ask( $input, $output, $question ) ) {
                throw new RuntimeException( 'You must supply a database username to complete the installation process.' );
            }
            $question = new Question( 'What is your database password: ', false );
            if( ! $databasePassword = $helper->ask( $input, $output, $question ) ) {
                throw new RuntimeException( 'You must supply a database password to complete the installation process.' );
            }
            $question = new Question( 'What is your email address (required for admin login): ', false );
            if( ! $this->emailAddress = $helper->ask( $input, $output, $question ) ) {
                throw new RuntimeException( 'You must supply a valid email address to complete the installation process.' );
            }
            $this->dbdetails->name = $databaseName;
            $this->dbdetails->user = $databaseUser;
            $this->dbdetails->pass = $databasePassword;
            $this->dbdetails->host = $databaseHost;
            $this->dbdetails->mamp = $usingMAMP;
            $this->dbdetails->port = $databasePort;
            $this->connect();
        }
        return $this->dbdetails;
    }

    /**
     * @return array
     */
    protected function getAvailableModules()
    {
        $data = json_decode( file_get_contents( $this->modulesUrl . 'packages.json' ) );
        $modules = json_decode( file_get_contents( $this->modulesUrl . key( $data->includes ) ) )->packages;
        $cleansed = [];
        foreach( $modules as $moduleName => $versions ) {
            $versions = $this->removeNonNumericVersions( (array) $versions );
            $latest = array_shift( $versions );
            $cleansed[ $latest['name'] ] = $latest['version'];
        }
        return array_filter( $cleansed );
    }

    /**
     * @param $versions
     * @return array
     */
    protected function removeNonNumericVersions( $versions )
    {
        $return = [];
        foreach( $versions as $key => $version ) {
            $cleansed = str_replace( '.', '', $version->version );
            if( is_numeric( $cleansed ) ) {
                $return[$version->version] = [
                    'name' => $version->name,
                    'version' => $version->version
                ];
            }
        }
        ksort( $return, SORT_NUMERIC );
        return array_reverse( $return );
    }

    /**
     * @param $output
     */
    protected function checkUsingLatestVersion( $output )
    {
        $data = json_decode( file_get_contents( 'https://packagist.org/p/ensphere/installer.json' ) );
        $versions = [];
        $tmpVersions = $data->packages->{'ensphere/installer'};
        foreach( $tmpVersions as $version => $package ) {
            $versions[] = $version;
        }
        usort( $versions, 'version_compare' );
        $latest = array_reverse( $versions )[0];
        if( $latest !== $this->version ) {
            throw new RuntimeException( "This version of the installer is out of date, please run 'composer global update \"ensphere/installer\"'." );
        }
    }

    /**
     * @param $input
     * @return string
     */
    protected function getAppName( $input )
    {
        return trim( strtolower( preg_replace( "/[^\w\d]+/i", '-', $input->getArgument( 'name' ) ) ) );
    }

    /**
     * @param $directory
     * @param string $message
     */
    protected function verifyApplicationDoesntExist( $directory, $message = 'Application already exists! Please delete it or rename it first.' )
    {
        if ( (is_dir( $directory) || is_file( $directory ) ) && $directory != getcwd() ) {
            throw new RuntimeException( $message );
        }
    }

    /**
     * @param $directory
     * @param $name
     * @param $position
     */
    protected function setupEnvExampleFile( $directory, $name, $position, $input, $output )
    {
        $tld = ( $customTld = $input->getArgument( 'tld' ) ) ? $customTld : '.localhost';
        $env = file_get_contents( __DIR__ . '/.env.example' );

        $coreArray = $position === 'front' ? $this->frontEndCoreEnvSettings() : $this->backEndCoreEnvSettings();
        $coreArray['APP_URL'] = "http://{$position}.{$name}{$tld}";

        if( $position === 'front' ) {
            $coreArray['FILESYSTEM_ROOT'] = "../{$name}-back/storage/app";
        } else {
            $coreArray['FRONT_END_URL'] = "http://front.{$name}{$tld}";
            $coreArray['FRONT_END_FOLDER'] = "{$name}-front";
        }

        $db = $this->getDatabaseDetails( $input, $output );

        $env = str_replace( [
            '[ENSPHERE_CORE_SETTINGS]',
            '[APP_URL]',
            '[MAMP_SOCKET]',
            '[DB_HOST]',
            '[DB_DATABASE]',
            '[DB_USERNAME]',
            '[DB_PASSWORD]',
            '[DB_PORT]'
        ], [
            str_replace( [ '%2F', '%3A' ], [ '/', ':' ], http_build_query( $coreArray, '', "\n" ) ),
            "http://{$position}.{$name}{$tld}",
            $db->mamp ? 'DB_SOCKET=/Applications/MAMP/tmp/mysql/mysql.sock' : '',
            $db->host,
            $db->name,
            $db->user,
            $db->pass,
            $db->port
        ], $env );
        file_put_contents( "{$directory}.env.example", $env );
    }

    /**
     * @param $directory
     * @param string $position
     * @return void
     */
    protected function addModulesToComposerFile( $directory, $position = 'front' )
    {
        $composer = json_decode( file_get_contents( "{$directory}composer.json" ) );
        $required = $position === 'front' ? $this->frontRequired : $this->backRequired;
        foreach( $required as $name ) {
            $composer->require->{$name} = "^" . preg_replace( "#\.\d+$#", '', $this->availableModules[ $name ] );
        }

        if( $position === 'front' ) {
            $composer->extra = new STDclass;
            $composer->extra->bower = new STDclass;
            $composer->extra->bower->require = new STDclass;
            $composer->extra->bower->require->jquery = '^1.8.0';
        }

        file_put_contents( "{$directory}composer.json", json_encode( $composer, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES ) );
    }

    /**
     * @return array
     */
    protected function frontEndCoreEnvSettings()
    {
        return [
            'USE_PASSPHRASE' => 'true',
            'PASSPHRASE' => 'purpose',
            'DISK_STORAGE' => 'local',
            'FILESYSTEM_ROOT' => null,
            'APP_URL' => null,
            'SITE_ID' => '1',
            'HOME' => getenv( 'HOME' ),
            'PATH' => getenv( 'PATH' )
        ];
    }

    /**
     * @return array
     */
    protected function backEndCoreEnvSettings()
    {
        return [
            'USE_PASSPHRASE' => 'true',
            'PASSPHRASE' => 'purpose',
            'DISK_STORAGE' => 'local',
            'FILESYSTEM_ROOT' => 'storage/app',
            'APP_URL' => null,
            'FRONT_END_URL' => null,
            'FRONT_END_FOLDER' => null,
            'HOME' => getenv( 'HOME' ),
            'PATH' => getenv( 'PATH' )
        ];
    }

}
