<?php

namespace App\Controller;

use App\Entity\Principal;
use App\Entity\User;
use App\Plugins\DavisIMipPlugin;
use App\Services\BasicAuth;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment as TwigEnvironment;

class DAVController extends AbstractController
{
    const AUTH_DIGEST = 'Digest';
    const AUTH_BASIC = 'Basic';

    /**
     * Is CalDAV enabled?
     *
     * @var bool
     */
    protected $calDAVEnabled;

    /**
     * is CardDAV enabled?
     *
     * @var bool
     */
    protected $cardDAVEnabled;

    /**
     * is WebDAV enabled?
     *
     * @var bool
     */
    protected $webDAVEnabled;

    /**
     * Mail address to send mails from.
     *
     * @var string
     */
    protected $inviteAddress;

    /**
     * HTTP authentication realm.
     *
     * @var string
     */
    protected $authRealm;

    /**
     * WebDAV Public directory.
     *
     * @var string
     */
    protected $publicDir;

    /**
     * WebDAV Temporary directory.
     *
     * @var string
     */
    protected $tmpDir;

    /**
     * Doctrine entity manager.
     *
     * @var EntityManagerInterface
     */
    protected $em;

    /**
     * The Twig engine.
     *
     * @var Twig\Environment
     */
    protected $twig;

    /**
     * The Swift_Mailer mailer service.
     *
     * @var \Swift_Mailer
     */
    protected $mailer;

    /**
     * Base URI of the server.
     *
     * @var string
     */
    protected $baseUri;

    /**
     * Basic Auth Backend class.
     *
     * @var App\Services\BasicAuth
     */
    protected $basicAuthBackend;

    /**
     * Server.
     *
     * @var \Sabre\DAV\Server
     */
    protected $server;

    public function __construct(\Swift_Mailer $mailer, TwigEnvironment $twig, BasicAuth $basicAuthBackend, UrlGeneratorInterface $router, EntityManagerInterface $entityManager, bool $calDAVEnabled = true, bool $cardDAVEnabled = true, bool $webDAVEnabled = false, ?string $inviteAddress, ?string $authMethod, ?string $authRealm, ?string $publicDir, ?string $tmpDir)
    {
        $this->calDAVEnabled = $calDAVEnabled;
        $this->cardDAVEnabled = $cardDAVEnabled;
        $this->webDAVEnabled = $webDAVEnabled;
        $this->inviteAddress = $inviteAddress ?? null;

        $this->authMethod = $authMethod;
        $this->authRealm = $authRealm ?? User::DEFAULT_AUTH_REALM;

        $this->publicDir = $publicDir;
        $this->tmpDir = $tmpDir;

        $this->em = $entityManager;
        $this->twig = $twig;
        $this->mailer = $mailer;
        $this->baseUri = $router->generate('dav', ['path' => '']);

        $this->basicAuthBackend = $basicAuthBackend;

        $this->initServer();
    }

    /**
     * @Route("/", name="home")
     */
    public function home()
    {
        return $this->render('index.html.twig');
    }

    private function initServer()
    {
        $pdo = $this->em->getConnection()->getWrappedConnection();

        /*
         * The backends.
         */
        switch ($this->authMethod) {
            case self::AUTH_DIGEST:
                $authBackend = new \Sabre\DAV\Auth\Backend\PDO($pdo);
                break;
            case self::AUTH_BASIC:
            default:
                $authBackend = $this->basicAuthBackend;
                break;
        }

        $authBackend->setRealm($this->authRealm);

        $principalBackend = new \Sabre\DAVACL\PrincipalBackend\PDO($pdo);

        /**
         * The directory tree.
         *
         * Basically this is an array which contains the 'top-level' directories in the
         * WebDAV server.
         */
        $nodes = [
            // /principals
            new \Sabre\CalDAV\Principal\Collection($principalBackend),
        ];

        if ($this->calDAVEnabled) {
            $calendarBackend = new \Sabre\CalDAV\Backend\PDO($pdo);
            $nodes[] = new \Sabre\CalDAV\CalendarRoot($principalBackend, $calendarBackend);
        }
        if ($this->cardDAVEnabled) {
            $carddavBackend = new \Sabre\CardDAV\Backend\PDO($pdo);
            $nodes[] = new \Sabre\CardDAV\AddressBookRoot($principalBackend, $carddavBackend);
        }
        if ($this->webDAVEnabled && $this->tmpDir && $this->publicDir) {
            $nodes[] = new \Sabre\DAV\FS\Directory($this->publicDir);
        }

        // The object tree needs in turn to be passed to the server class
        $this->server = new \Sabre\DAV\Server($nodes);
        $this->server->setBaseUri($this->baseUri);

        // Plugins
        $this->server->addPlugin(new \Sabre\DAV\Auth\Plugin($authBackend, $this->authRealm));
        $this->server->addPlugin(new \Sabre\DAV\Browser\Plugin());
        $this->server->addPlugin(new \Sabre\DAV\Sync\Plugin());

        $aclPlugin = new \Sabre\DAVACL\Plugin();
        $aclPlugin->hideNodesFromListings = true;
        // Fetch admins, if any
        $admins = $this->em->getRepository(Principal::class)->findBy(['isAdmin' => true]);
        foreach ($admins as $principal) {
            $aclPlugin->adminPrincipals[] = $principal->getUri();
        }

        $this->server->addPlugin($aclPlugin);

        $this->server->addPlugin(new \Sabre\DAV\PropertyStorage\Plugin(
            new \Sabre\DAV\PropertyStorage\Backend\PDO($pdo)
        ));

        // CalDAV plugins
        if ($this->calDAVEnabled) {
            $this->server->addPlugin(new \Sabre\DAV\Sharing\Plugin());
            $this->server->addPlugin(new \Sabre\CalDAV\Plugin());
            $this->server->addPlugin(new \Sabre\CalDAV\Schedule\Plugin());
            $this->server->addPlugin(new \Sabre\CalDAV\SharingPlugin());
            $this->server->addPlugin(new \Sabre\CalDAV\ICSExportPlugin());
            if ($this->inviteAddress) {
                $this->server->addPlugin(new DavisIMipPlugin($this->twig, $this->mailer, $this->inviteAddress));
            }
        }

        // CardDAV plugins
        if ($this->cardDAVEnabled) {
            $this->server->addPlugin(new \Sabre\CardDAV\Plugin());
            $this->server->addPlugin(new \Sabre\CardDAV\VCFExportPlugin());
        }

        // WebDAV plugins
        if ($this->webDAVEnabled && $this->tmpDir && $this->publicDir) {
            $lockBackend = new \Sabre\DAV\Locks\Backend\File($this->tmpDir.'/locksdb');
            $this->server->addPlugin(new \Sabre\DAV\Locks\Plugin($lockBackend));
            //$this->server->addPlugin(new \Sabre\DAV\Browser\GuessContentType()); // Waiting for https://github.com/sabre-io/dav/pull/1203
            $this->server->addPlugin(new \Sabre\DAV\TemporaryFileFilterPlugin($this->tmpDir));
        }
    }

    /**
     * @Route("/dav/{path}", name="dav", requirements={"path":".*"})
     */
    public function dav(Request $request, string $path)
    {
        // \Sabre\DAV\Server does not let us use a custom SAPI, and its behaviour
        // is to directly output headers and content to php://output. Hence, we
        // let the headers pass (we have not choice) and capture the output in a
        // buffer.
        // This allows us to use a Response, and not to break the events triggered
        // by Symfony after the response is sent, like for instance the TERMINATE
        // event from the Kernel, that is used to send emails...

        ob_start(); // Does not capture headers!
        $this->server->start();

        $output = ob_get_contents();
        ob_end_clean();

        return new Response($output, http_response_code(), []);
    }
}
