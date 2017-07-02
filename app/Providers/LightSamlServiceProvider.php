<?php

namespace App\Providers;

use App\SAML2\Bridge\OwnContainer;
use App\SAML2\Bridge\PartyContainer;
use App\SAML2\Bridge\ProviderContainer;
use App\SAML2\Bridge\ServiceContainer;
use App\SAML2\Bridge\StoreContainer;
use App\SAML2\Bridge\SystemContainer;
use App\SAML2\Provider\AttributeValueProviderBuilder;
use App\SAML2\Provider\NameIdValueProviderBuilder;
use App\SAML2\Session\SsoStateSessionStore;
use App\SAML2\Store\EntityDescriptorStoreBuilder;
use Illuminate\Contracts\Session\Session;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LightSaml\Binding\BindingFactory;
use LightSaml\Bridge\Pimple\Container\CredentialContainer;
use LightSaml\Builder\EntityDescriptor\SimpleEntityDescriptorBuilder;
use LightSaml\Credential\KeyHelper;
use LightSaml\Credential\X509Certificate;
use LightSaml\Credential\X509Credential;
use LightSaml\Logout\Resolver\Logout\LogoutSessionResolver;
use LightSaml\Meta\TrustOptions\TrustOptions;
use LightSaml\Provider\EntityDescriptor\EntityDescriptorProviderInterface;
use LightSaml\Provider\Session\FixedSessionInfoProvider;
use LightSaml\Provider\TimeProvider\SystemTimeProvider;
use LightSaml\Resolver\Credential\Factory\CredentialResolverFactory;
use LightSaml\Resolver\Endpoint\BindingEndpointResolver;
use LightSaml\Resolver\Endpoint\CompositeEndpointResolver;
use LightSaml\Resolver\Endpoint\DescriptorTypeEndpointResolver;
use LightSaml\Resolver\Endpoint\IndexEndpointResolver;
use LightSaml\Resolver\Endpoint\LocationEndpointResolver;
use LightSaml\Resolver\Endpoint\ServiceTypeEndpointResolver;
use LightSaml\Resolver\Session\SessionProcessor;
use LightSaml\Resolver\Signature\OwnSignatureResolver;
use LightSaml\SamlConstants;
use LightSaml\Store\Credential\Factory\CredentialFactory;
use LightSaml\Store\EntityDescriptor\FixedEntityDescriptorStore;
use LightSaml\Store\Id\NullIdStore;
use LightSaml\Store\Request\RequestStateSessionStore;
use LightSaml\Store\TrustOptions\FixedTrustOptionsStore;
use LightSaml\Validator\Model\Assertion\AssertionTimeValidator;
use LightSaml\Validator\Model\Assertion\AssertionValidator;
use LightSaml\Validator\Model\NameId\NameIdValidator;
use LightSaml\Validator\Model\Signature\SignatureValidator;
use LightSaml\Validator\Model\Statement\StatementValidator;
use LightSaml\Validator\Model\Subject\SubjectValidator;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

class LightSamlServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->registerCredentials();
        $this->registerOwn();
        $this->registerParty();
        $this->registerProvider();
        $this->registerService();
        $this->registerStore();
        $this->registerSystem();
    }

    private function registerOwn()
    {
        $this->app->bind(OwnContainer::OWN_CREDENTIALS, function (Application $app) {
            /** @var FilesystemManager $fs */
            $fs = $app->make(FilesystemManager::class);
            $drive = $fs->drive(config('saml.disk'));

            $credential = (new X509Credential(
                (new X509Certificate())->loadPem($drive->get('keys/saml.crt')),
                KeyHelper::createPrivateKey($drive->get('keys/saml.key'), null)
            ))
                ->setEntityId($app->make('url')->route('saml.idp.metadata'));

            return [$credential];
        });

        $this->app->bind(OwnContainer::OWN_ENTITY_DESCRIPTOR_PROVIDER, function (Application $app) {
            /** @var X509Credential[] $credentials */
            $credentials = $app->make(OwnContainer::OWN_CREDENTIALS);

            $builder = new SimpleEntityDescriptorBuilder(
                $app->make('url')->route('saml.idp.metadata'),
                null,
                $app->make('url')->route('saml.idp.sso'),
                $credentials[0]->getCertificate()
            );

            $entityDescriptor = $builder->get();
            $entityDescriptor->getFirstIdpSsoDescriptor()->addNameIDFormat(SamlConstants::NAME_ID_FORMAT_EMAIL);

            return $builder;
        });
    }

    private function registerCredentials()
    {
        $this->app->bind(CredentialContainer::CREDENTIAL_STORE, function (Application $app) {
            $factory = new CredentialFactory();

            return $factory->build(
                $app->make(PartyContainer::IDP_ENTITY_DESCRIPTOR),
                $app->make(PartyContainer::SP_ENTITY_DESCRIPTOR),
                $app->make(OwnContainer::OWN_CREDENTIALS)
            );
        });
    }

    private function registerParty()
    {
        $this->app->bind(PartyContainer::IDP_ENTITY_DESCRIPTOR, function () {
            return new FixedEntityDescriptorStore();
        });

        $this->app->bind(PartyContainer::SP_ENTITY_DESCRIPTOR, function (Application $app) {
            $fs = $app->make(FilesystemManager::class);
            $drive = $fs->drive(config('saml.disk'));

            return (new EntityDescriptorStoreBuilder($drive))->build();
        });

        $this->app->bind(PartyContainer::TRUST_OPTIONS_STORE, function () {
            return new FixedTrustOptionsStore(new TrustOptions());
        });
    }

    private function registerService()
    {
        $this->app->bind(ServiceContainer::NAME_ID_VALIDATOR, function () {
            return new NameIdValidator();
        });

        $this->app->bind(ServiceContainer::ASSERTION_TIME_VALIDATOR, function () {
            return new AssertionTimeValidator();
        });

        $this->app->bind(ServiceContainer::ASSERTION_VALIDATOR, function (Application $app) {
            $nameIdValidator = $app->make(ServiceContainer::NAME_ID_VALIDATOR);

            return new AssertionValidator(
                $nameIdValidator,
                new SubjectValidator($nameIdValidator),
                new StatementValidator()
            );
        });

        $this->app->bind(ServiceContainer::ENDPOINT_RESOLVER, function () {
            return new CompositeEndpointResolver(array(
                new BindingEndpointResolver(),
                new DescriptorTypeEndpointResolver(),
                new ServiceTypeEndpointResolver(),
                new IndexEndpointResolver(),
                new LocationEndpointResolver(),
            ));
        });

        $this->app->bind(ServiceContainer::BINDING_FACTORY, function (Application $app) {
            return new BindingFactory($app->make(SystemContainer::EVENT_DISPATCHER));
        });

        $this->app->bind(ServiceContainer::CREDENTIAL_RESOLVER, function (Application $app) {
            return (new CredentialResolverFactory($app->make(CredentialContainer::CREDENTIAL_STORE)))->build();
        });

        $this->app->bind(ServiceContainer::SIGNATURE_RESOLVER, function (Application $app) {
            return new OwnSignatureResolver($app->make(ServiceContainer::CREDENTIAL_RESOLVER));
        });

        $this->app->bind(ServiceContainer::SIGNATURE_VALIDATOR, function (Application $app) {
            return new SignatureValidator($app->make(ServiceContainer::CREDENTIAL_RESOLVER));
        });

        $this->app->bind(ServiceContainer::LOGOUT_SESSION_RESOLVER, function (Application $app) {
            return new LogoutSessionResolver($app->make(StoreContainer::SSO_STATE_STORE));
        });

        $this->app->bind(ServiceContainer::SESSION_PROCESSOR, function (Application $app) {
            return new SessionProcessor($app->make(StoreContainer::SSO_STATE_STORE), $app->make(SystemContainer::TIME_PROVIDER));
        });
    }

    private function registerProvider()
    {
        $this->app->bind(AttributeValueProviderBuilder::class, function (Application $app) {
            return new AttributeValueProviderBuilder(
                $app->make('auth.driver')
            );
        });

        $this->app->bind(NameIdValueProviderBuilder::class, function (Application $app) {
            /** @var EntityDescriptorProviderInterface $ownEntityDescriptor */
            $ownEntityDescriptor = $app->make(OwnContainer::OWN_ENTITY_DESCRIPTOR_PROVIDER);

            return new AttributeValueProviderBuilder(
                $app->make('auth.driver'),
                $ownEntityDescriptor->get()
            );
        });

        $this->app->bind(ProviderContainer::ATTRIBUTE_VALUE_PROVIDER, function (Application $app) {
            return $app->make(AttributeValueProviderBuilder::class)->build();
        });

        $this->app->bind(ProviderContainer::SESSION_INFO_PROVIDER, function () {
            return new FixedSessionInfoProvider(
                time() - 600,
                'session-index',
                SamlConstants::AUTHN_CONTEXT_PASSWORD_PROTECTED_TRANSPORT
            );
        });

        $this->app->bind(ProviderContainer::NAME_ID_PROVIDER, function (Application $app) {
            return $app->make(NameIdValueProviderBuilder::class)->build();
        });
    }

    private function registerStore()
    {
        $this->app->bind(StoreContainer::REQUEST_STATE_STORE, function (Application $app) {
            return new RequestStateSessionStore($app->make(SystemContainer::SESSION), 'main');
        });

        $this->app->bind(StoreContainer::ID_STATE_STORE, function (Application $app) {
            return new NullIdStore();
        });

        $this->app->bind(StoreContainer::SSO_STATE_STORE, function (Application $app) {
            return new SsoStateSessionStore($app->make(SystemContainer::SESSION), 'samlsso');
        });
    }

    private function registerSystem()
    {
        $this->app->bind(SystemContainer::REQUEST, function (Application $app) {
            return $app->make('request');
        });

        $this->app->bind(SystemContainer::SESSION, function (Application $app) {
            return $app->make(Session::class);
        });

        $this->app->bind(SystemContainer::TIME_PROVIDER, function () {
            return new SystemTimeProvider();
        });

        $this->app->bind(SystemContainer::EVENT_DISPATCHER, function () {
            return new EventDispatcher();
        });

        $this->app->bind(SystemContainer::LOGGER, LoggerInterface::class);
    }
}