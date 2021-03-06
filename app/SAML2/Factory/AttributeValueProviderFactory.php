<?php

namespace App\SAML2\Factory;

use App\Entities\User;
use Illuminate\Contracts\Auth\Guard;
use LightSaml\ClaimTypes;
use LightSaml\Model\Assertion\Attribute;
use LightSaml\Provider\Attribute\FixedAttributeValueProvider;

class AttributeValueProviderFactory
{
    /**
     * @var Guard
     */
    protected $guard;

    public function __construct(Guard $guard)
    {
        $this->guard = $guard;
    }

    /**
     * @return FixedAttributeValueProvider
     */
    public function build(): FixedAttributeValueProvider
    {
        return (new FixedAttributeValueProvider())
            ->setAttributes($this->getMapping());
    }

    protected function getUser(): User
    {
        $user = $this->guard->user();

        if ($user instanceof User) {
            return $user;
        }

        return null;
    }

    /**
     * @return Attribute[]
     */
    protected function getMapping(): array
    {
        $user = $this->getUser();

        return [
            new Attribute(
                ClaimTypes::PPID,
                $user->getId()
            ),
            new Attribute(
                ClaimTypes::NAME,
                $user->getFirstName()
            ),
            new Attribute(
                ClaimTypes::GIVEN_NAME,
                $user->getFirstName()
            ),
            new Attribute(
                ClaimTypes::SURNAME,
                $user->getLastName()
            ),
            new Attribute(
                ClaimTypes::EMAIL_ADDRESS,
                $user->getEmail()
            )
        ];
    }
}