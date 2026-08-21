<?php
namespace GlpiPlugin\Ticketeamer\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Ticketeamer\Config;
use GlpiPlugin\Ticketeamer\GraphClient;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OAuthCallbackController extends AbstractController
{
    #[Route('/plugins/ticketeamer/oauth/callback', name: 'ticketeamer_oauth_callback', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        \Session::checkRight('config', UPDATE);

        $state = $request->query->getString('state');
        $expected = $_SESSION['ticketeamer_oauth_state'] ?? '';
        unset($_SESSION['ticketeamer_oauth_state']);

        if ($state === '' || $expected === '' || !hash_equals($expected, $state)) {
            throw new \RuntimeException('Invalid OAuth state.');
        }

        $error = $request->query->getString('error');
        if ($error !== '') {
            $description = $request->query->getString('error_description');
            throw new \RuntimeException('Microsoft OAuth error: ' . ($description !== '' ? $description : $error));
        }

        $code = $request->query->getString('code');
        if ($code === '') {
            throw new \RuntimeException('Microsoft OAuth callback did not contain an authorization code.');
        }

        Config::save([
            'refresh_token' => GraphClient::exchangeAuthorizationCode($code),
        ]);

        \Session::addMessageAfterRedirect(__('Microsoft Teams authorization completed successfully.', 'ticketeamer'));
        return new RedirectResponse('/plugins/ticketeamer/config');
    }
}
