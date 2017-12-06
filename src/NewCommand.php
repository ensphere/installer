<?php

namespace Ensphere\Installer;

use ZipArchive;
use RuntimeException;
use GuzzleHttp\Client;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;
use Ensphere\Installer\Models\User;
use Ensphere\Installer\Models\RoleUser;
use Ensphere\Installer\Models\Site;
use Illuminate\Hashing\BcryptHasher;
use STDclass;

class NewCommand extends Command
{

    protected $dbdetails;

    protected $capsule;

    protected $emailAddress;

    protected $version = '1.0.19';

    protected $hasher;

    protected $user;

    protected $modulesUrl = 'https://modules.testing.pm/';

    protected $availableModules = [];

    protected $frontRequired = [
        'purposemedia/front-banners',
        'purposemedia/front-blog',
        'purposemedia/front-contact',
        'purposemedia/front-container',
        'purposemedia/front-customers',
        'purposemedia/front-delivery',
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
        'purposemedia/front-multibuy'
    ];

    protected $backRequired = [
        'purposemedia/sites',
        'purposemedia/users',
        'purposemedia/admin-ads-manager',
        'purposemedia/admin-banners',
        'purposemedia/admin-blog',
        'purposemedia/admin-contact',
        'purposemedia/admin-customers',
        'purposemedia/admin-delivery',
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

    protected function configure()
    {
        $this
            ->setName( 'new' )
            ->setDescription( 'Create a new Ensphere application.' )
            ->addArgument( 'name', InputArgument::OPTIONAL )
            ->addArgument( 'tld', InputArgument::OPTIONAL );
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
     * Execute the command.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return void
     */
    protected function execute( InputInterface $input, OutputInterface $output )
    {
        if ( ! class_exists( 'ZipArchive' ) ) {
            throw new RuntimeException( 'The Zip PHP extension is not installed. Please install it and try again.' );
        }
        $this->hasher = new BcryptHasher();
        $this->checkUsingLatestVersion( $output );
        $name = strtolower( preg_replace( "/[^\w\d]+/i", '-', $input->getArgument( 'name' ) ) );
        $this->verifyApplicationDoesntExist(
            $directory = ( $name ) ? getcwd() . '/' . $name : getcwd()
        );

        $this->availableModules = $this->getAvailableModules();
        $this->installAndSetupBackendApplication( $name, $directory, $input, $output );
        $this->installAndSetupFrontendApplication( $name, $directory, $input, $output );

        $output->writeln( "
<fg=green>Your Ensphere application is successfully installed!</>

<fg=blue>Credentials:</>

<fg=yellow>Front URL:</>    <fg=green>http://front.{$name}.app</>
<fg=yellow>Back URL:</>     <fg=green>http://back.{$name}.app</>

<fg=yellow>Email:</>        <fg=green>{$this->user->email}</>
<fg=yellow>Password:</>     <fg=green>{$this->user->password_plain}</>
        " );

    }

    /**
     * @param $name
     * @param $position
     * @param $path
     * @param $output
     */
    protected function rename( $name, $position, $path, $output )
    {
        $this->runCommand( $output, $path, "php artisan ensphere:rename --vendor={$name} --module={$position}" );
    }

    /**
     * @param $directory
     */
    protected function verifyApplicationDoesntExist( $directory )
    {
        if ( (is_dir( $directory) || is_file( $directory ) ) && $directory != getcwd() ) {
            throw new RuntimeException( 'Application already exists! Please delete it or rename it first.' );
        }
    }

    /**
     * @return string
     */
    protected function makeFilename()
    {
        return getcwd() . '/ensphere_' . md5( time() . uniqid() ) . '.zip';
    }

    /**
     * @param $zipFile
     * @return $this
     */
    protected function download( $zipFile )
    {
        $response = ( new Client )->get( 'https://github.com/ensphere/ensphere/archive/master.zip' );
        file_put_contents( $zipFile, $response->getBody() );
        return $this;
    }

    /**
     * @param $zipFile
     * @param $directory
     * @return $this
     */
    protected function extract( $zipFile, $directory )
    {
        $archive = new ZipArchive;
        $archive->open( $zipFile );
        $archive->extractTo( $directory );
        $archive->close();
        return $this;
    }

    /**
     * @param $zipFile
     * @return $this
     */
    protected function cleanUp( $zipFile )
    {
        @chmod( $zipFile, 0777 );
        @unlink( $zipFile );
        return $this;
    }

    /**
     * @return string
     */
    protected function findComposer()
    {
        if( file_exists( getcwd() . '/composer.phar' ) ) {
            return '"' . PHP_BINARY . '" composer.phar';
        }
        return 'composer';
    }

    /**
     * @param $directory
     * @param $name
     * @param $position
     */
    protected function setupEnvExampleFile( $directory, $name, $position, $input, $output )
    {
        $tld = ( $customTld = $input->getArgument( 'tld' ) ) ? $customTld : '.app';
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
     * @param $data
     */
    protected function addModulesJsonFile( $directory, $data )
    {
        file_put_contents( "{$directory}modules.json", $data );
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
     * @param $name
     * @param $directory
     * @param $input
     * @param $output
     */
    private function installAndSetupBackendApplication( $name, $directory, $input, $output )
    {
        $output->writeln( '<info>Creating Backend application...</info>' );
        $this->download( $zipFile = $this->makeFilename() )->extract( $zipFile, $directory )->cleanUp( $zipFile );
        $newPath = "{$directory}/{$name}-back/";

        rename( "{$directory}/ensphere-master/", $newPath );

        $this->setupEnvExampleFile( $newPath, $name, 'back', $input, $output  );
        $this->addModulesToComposerFile( $newPath, 'back' );
        $this->installProject( $newPath, $output );
        $this->copyEnv( $newPath, $output );
        $this->generateNewKey( $newPath, $output );
        $this->composerUpdate( $newPath, $output );
        $this->rename( $name, 'back', $newPath, $output );
        $this->runCommand( $output, $newPath, "php artisan vendor:publish --tag=install" );
        $this->createUser( $name );
        $this->createSite( $input, $name );
    }

    /**
     * @param $input
     * @param $name
     */
    protected function createSite( $input, $name )
    {
        $siteName = ucwords( $input->getArgument( 'name' ) );
        Site::create([
            'name' => $siteName,
            'default' => 1,
            'meta_title' => $siteName,
            'meta_description' => $siteName,
            'country_id' => 239,
            'email' => $this->emailAddress,
            'order_notifcation_email' => $this->emailAddress,
            'emails_from' => $this->emailAddress,
            'vat_rate' => 20.00,
            'price_storage_type' => 1,
            'display_prices_for' => 1,
            'terms_conditions_version' => 1.0,
            'front_end_url' => "{$name}-front",
            'front_end_folder' => "http://front.{$name}.app",
            'emails_from_name' => $siteName,
            'smtp_username' => 'AKIAJEUDEMHOCP2Y4E3A',
            'smtp_password' => 'AjZ0Gj9P2xpr9JK9q/2ae9Dd4FYpmG9ZOM92txTGwtYE',
            'smtp_port' => '587',
            'smtp_host' => 'email-smtp.eu-west-1.amazonaws.com'
        ]);
    }

    /**
     * @param $name
     * @param $directory
     * @param $input
     * @param $output
     */
    private function installAndSetupFrontendApplication( $name, $directory, $input, $output )
    {
        $output->writeln( '<info>Creating Frontend application...</info>' );
        $this->download( $zipFile = $this->makeFilename() )->extract( $zipFile, $directory )->cleanUp( $zipFile );
        $newPath = "{$directory}/{$name}-front/";

        rename( "{$directory}/ensphere-master/", $newPath );

        $this->setupEnvExampleFile( $newPath, $name, 'front', $input, $output );
        $this->addModulesToComposerFile( $newPath, 'front' );
        $this->installProject( $newPath, $output );
        $this->copyEnv( $newPath, $output );
        $this->generateNewKey( $newPath, $output );
        $this->composerUpdate( $newPath, $output );
        $this->rename( $name, 'front', $newPath, $output );
        $this->runCommand( $output, $newPath, "php artisan vendor:publish --tag=install" );
    }

    /**
     * @param $name
     */
    protected function createUser( $name )
    {
        $password = substr( sha1( microtime() ), 0, 8 );
        $user = User::create([
            'email'         => $this->emailAddress,
            'password'      => $this->hasher->make( $password ),
            'name'          => 'Purpose Media',
            'active'        => 1,
            'media_id'      => 0,
            'job_role'      => 'E-commerce Specialists',
            'bio'           => '<p>Purpose Media is an award-winning full service digital agency. Our purpose is to increase sales, reduce costs and improve the profitability of our clients.</p><p>We specialise in e-commerce, digital marketing, creative design, content creation and video production.</p>',
            'twitter_url'   => 'https://www.twitter.com/purposemediauk',
            'facebook_url'  => 'https://www.facebook.com/purposemediauk',
            'instagram_url' => 'https://www.instagram.com/purposemediauk/',
            'linkedin_url'  => 'https://www.linkedin.com/company/1848387/'
        ]);
        RoleUser::create([
            'role_id' => 1,
            'user_id' => 1
        ]);

        $user->password_plain = $password;
        $this->user = $user;

        $message = "
        Your main account has been created for {$name}.\n\n
        Email: {$user->email}\n
        Password: {$password}\n\n
        You will be granted Keymaster permissions.";
        mail( $this->emailAddress, 'Ensphere Project Authentication', $message );
    }

    /**
     * @param $path
     * @param $output
     */
    protected function installProject( $path, $output )
    {
        $this->runCommand( $output, $path, 'php composer.phar self-update; php composer.phar install --no-scripts' );
    }

    /**
     * @param $path
     * @param $output
     */
    protected function copyEnv( $path, $output )
    {
        $this->runCommand( $output, $path, "php -r \"copy('.env.example', '.env');\"" );
    }

    /**
     * @param $path
     * @param $output
     */
    protected function generateNewKey( $path, $output )
    {
        $this->runCommand( $output, $path, "php artisan key:generate" );
    }

    /**
     * @param $path
     * @param $output
     */
    protected function composerUpdate( $path, $output )
    {
        $this->runCommand( $output, $path, "php composer.phar update" );
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

    /**
     * @param $output
     * @param $path
     * @param $command
     */
    protected function runCommand( $output, $path, $command )
    {
        $process = new Process( $command, $path, null, null, null );
        if ( '\\' !== DIRECTORY_SEPARATOR && file_exists( '/dev/tty' ) && is_readable( '/dev/tty' ) ) {
            $process->setTty( true );
        }
        $process->run( function ( $type, $line ) use ( $output ) {
            $output->write( $line );
        });
    }

}
