<?php
namespace GlpiPlugin\Ticketeamer\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Ticketeamer\Config;
use GlpiPlugin\Ticketeamer\GraphClient;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ConfigController extends AbstractController
{
    #[Route('/plugins/ticketeamer/config', name: 'ticketeamer_config', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        \Session::checkRight('config', UPDATE);

        if ($request->isMethod('POST')) {
            $enabled = $request->request->getBoolean('enabled');
            $prefix = trim($request->request->getString('message_prefix'));
            $retryLimit = max(1, min(20, $request->request->getInt('retry_limit', 5)));

            Config::save([
                'tenant_id' => trim($request->request->getString('tenant_id')),
                'client_id' => trim($request->request->getString('client_id')),
                'redirect_uri' => trim($request->request->getString('redirect_uri')),
                'enabled' => $enabled ? 1 : 0,
                'message_prefix' => $prefix !== '' ? $prefix : 'Novo chamado GLPI',
                'retry_limit' => $retryLimit,
            ]);

            \Session::addMessageAfterRedirect(__('Configuration saved.', 'ticketeamer'));
            return new RedirectResponse('/plugins/ticketeamer/config');
        }

        $config = Config::all();
        $state = bin2hex(random_bytes(24));
        $_SESSION['ticketeamer_oauth_state'] = $state;

        $oauthReady = $config['tenant_id'] !== ''
            && $config['client_id'] !== ''
            && $config['redirect_uri'] !== ''
            && getenv('GLPI_TEAMS_BRIDGE_CLIENT_SECRET');

        return $this->render('ticketeamer/config.html.twig', [
            'config' => $config,
            'oauth_ready' => (bool) $oauthReady,
            'authorization_url' => $oauthReady ? GraphClient::authorizationUrl($state) : null,
            'encryption_key_configured' => (bool) getenv('GLPI_TEAMS_BRIDGE_ENCRYPTION_KEY'),
            'client_secret_configured' => (bool) getenv('GLPI_TEAMS_BRIDGE_CLIENT_SECRET'),
            'authorized' => !empty($config['refresh_token']),
        ]);
    }
}
