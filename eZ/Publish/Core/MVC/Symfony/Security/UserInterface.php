<?php

/**
 * File containing the UserInterface class.
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 *
 * @version //autogentag//
 */
namespace eZ\Publish\Core\MVC\Symfony\Security;

use eZ\Publish\API\Repository\Values\User\User as APIUser;
use Symfony\Component\Security\Core\User\AdvancedUserInterface;

/**
 * Interface for Repository based users.
 */
interface UserInterface extends AdvancedUserInterface
{
    /**
     * @return \eZ\Publish\API\Repository\Values\User\User
     */
    public function getAPIUser();

    /**
     * @param \eZ\Publish\API\Repository\Values\User\User $apiUser
     */
    public function setAPIUser(APIUser $apiUser);
}
