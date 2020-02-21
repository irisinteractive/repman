<?php

declare(strict_types=1);

namespace Buddy\Repman\Security;

use Buddy\Repman\Entity\User;
use Buddy\Repman\Repository\UserRepository;
use Buddy\Repman\Service\GitHubApi;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\SocialAuthenticator;
use Omines\OAuth2\Client\Provider\GitlabResourceOwner;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class GitLabAuthenticator extends SocialAuthenticator
{
    private ClientRegistry $clientRegistry;
    private UserRepository $users;
    private GitHubApi $gitHubApi;
    private RouterInterface $router;
    private Session $session;

    public function __construct(ClientRegistry $clientRegistry, UserRepository $users, GitHubApi $gitHubApi, RouterInterface $router, Session $session)
    {
        $this->clientRegistry = $clientRegistry;
        $this->users = $users;
        $this->gitHubApi = $gitHubApi;
        $this->router = $router;
        $this->session = $session;
    }

    public function supports(Request $request)
    {
        return $request->attributes->get('_route') === 'login_gitlab_check';
    }

    public function getCredentials(Request $request)
    {
        return $this->fetchAccessToken($this->clientRegistry->getClient('gitlab-auth'));
    }

    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        /** @var GitlabResourceOwner $gitLabUser */
        $gitLabUser = $this->clientRegistry->getClient('gitlab-auth')->fetchUserFromToken($credentials);

        $user = $this->users->findOneBy(['email' => $gitLabUser->getEmail()]);
        if (!$user instanceof User) {
            throw new UsernameNotFoundException();
        }

        return $user;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $this->session->getFlashBag()->add('danger', strtr($exception->getMessageKey(), $exception->getMessageData()));

        return new RedirectResponse($this->router->generate('app_login'));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $providerKey): Response
    {
        return new RedirectResponse($this->router->generate('index'));
    }

    /**
     * @codeCoverageIgnore auth is started in LoginFormAuthenticator, see security.yml -> entry_point
     */
    public function start(Request $request, AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->router->generate('app_login'));
    }
}
