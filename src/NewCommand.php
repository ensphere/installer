<?php

namespace Ensphere\Installer;

use ZipArchive;
use RuntimeException;
use GuzzleHttp\Client;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Ensphere\Installer\Models\User;
use Ensphere\Installer\Models\RoleUser;
use Ensphere\Installer\Models\Site;
use Illuminate\Hashing\BcryptHasher;
use STDclass;

class NewCommand extends BaseCommand
{



    protected $capsule;

    protected $emailAddress;

    protected $hasher;

    protected $user;

    protected function configure()
    {
        $this
            ->setName( 'new' )
            ->setDescription( 'Create a new Ensphere application.' )
            ->addArgument( 'name', InputArgument::OPTIONAL )
            ->addArgument( 'tld', InputArgument::OPTIONAL );
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
        $name = $this->getAppName( $input );
        $this->verifyApplicationDoesntExist(
            $directory = ( $name ) ? getcwd() . '/' . $name : getcwd()
        );

        $this->availableModules = $this->getAvailableModules();
        $this->installAndSetupBackendApplication( $name, $directory, $input, $output );
        $this->installAndSetupFrontendApplication( $name, $directory, $input, $output );

        $output->writeln( "
<fg=green>Your Ensphere application is successfully installed!</>

<fg=blue>Credentials:</>

<fg=yellow>Front URL:</>    <fg=green>http://front.{$name}.localhost</>
<fg=yellow>Back URL:</>     <fg=green>http://back.{$name}.localhost</>

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
        $response = ( new Client() )->get( 'https://codeload.github.com/ensphere/ensphere/zip/master' );
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
     * @param $data
     */
    protected function addModulesJsonFile( $directory, $data )
    {
        file_put_contents( "{$directory}modules.json", $data );
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
            'front_end_folder' => "http://front.{$name}.localhost",
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

}
