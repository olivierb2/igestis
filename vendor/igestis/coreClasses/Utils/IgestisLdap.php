<?php

namespace Igestis\Utils;

/**
 * The object contain each static method to manage the LDAP repository
 *
 * @author Gilles Hemmerlé
 */
class IgestisLdap
{
    private $ldapConnexion;

    public static function ldapEscapeString($string)
    {
        //$string = addslashes($string);
        $string = str_replace("\\", '\\\\', $string);
        $string = str_replace("(", '\(', $string);
        $string = str_replace(")", '\)', $string);
        $string = str_replace(",", '\,', $string);
        return $string;
    }

    public static function ldapEscapeDistinguisedName($string)
    {
        //$string = addslashes($string);
        $string = str_replace("\\", '\\\\', $string);
        $string = str_replace("(", '\(', $string);
        $string = str_replace(")", '\)', $string);
        //$string = str_replace(",", '\,', $string);
        return $string;
    }


    /**
     * Return an ldap connexion
     * @return \LDAP Ldap connexion
     */
    public static function getConnexion($username = null, $password = null, $ou = null)
    {
        $ldap = new \LDAP(\ConfigIgestisGlobalVars::ldapUris(), \ConfigIgestisGlobalVars::ldapBase(), \ConfigIgestisGlobalVars::ldapVersion());
        $adminAuth = false;
        if (!$username) {
            $username = \ConfigIgestisGlobalVars::ldapAdmin();
            $adminAuth = true;
        }
        if (!$password) {
            $password = \ConfigIgestisGlobalVars::ldapPassword();
        }

        if (!$adminAuth) {
            $ldap->bind(str_replace("%u", $username, \ConfigIgestisGlobalVars::ldapCustomBind()), $password);
        } else {
            $ldap->bind($username, $password);
        }

        return $ldap;
    }

    public function getStandardConnexion($username = null, $password = null, $ou = null)
    {
        $ldap = ldap_connect(\ConfigIgestisGlobalVars::ldapUris());
        if (\ConfigIgestisGlobalVars::ldapVersion() != null) {
            ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, \ConfigIgestisGlobalVars::ldapVersion());
        }
        ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);

        $adminAuth = false;
        if (!$username) {
            $username = \ConfigIgestisGlobalVars::ldapAdmin();
            $adminAuth = true;
        }
        if (!$password) {
            $password = \ConfigIgestisGlobalVars::ldapPassword();
        }

        if (!$adminAuth) {
            ldap_bind(str_replace("%u", $username, \ConfigIgestisGlobalVars::ldapCustomBind()), $password);
        } else {
            ldap_bind($ldap, $username, $password);
        }

        $this->ldapConnexion = $ldap;
        return $ldap;
    }

    /**
     * Add or remove a user from a group
     * @param string $userDn
     * @param string $groupDn
     * @param boolean $giveAccess
     * @return null
     */
    public function switchUserToGroup($userDn, $groupDn, $giveAccess)
    {

        $group = @ldap_search($this->ldapConnexion, \ConfigIgestisGlobalVars::ldapBase(), '(&(objectCategory=group)(distinguishedName=' . $this->ldapEscapeDistinguisedName($groupDn) . '))', array('member'));
        $info = @ldap_get_entries($this->ldapConnexion, $group);

        if ($info['count'] > 0) {
            if (!isset($info[0]['member'])) {
                $info[0]['member'] = array('count' => 0);
            }
            $users = array();
            $alreadyInGroup = false;
            if (isset($info['0']['member']['count'])) {
                for ($i = 0; $i < $info['0']['member']['count']; $i++) {
                    if ($info['0']['member'][$i] == $userDn) {
                        $alreadyInGroup = true;
                        if (!$giveAccess) {
                            continue;
                        }
                    }
                    $users[] = $info['0']['member'][$i];
                }
            }

            if (!$alreadyInGroup && $giveAccess) {
                $users[] = $userDn;
            }

            @ldap_mod_replace($this->ldapConnexion, $groupDn, array(
                'member' => $users,
            ));

            $error = ldap_error($this->ldapConnexion);
            if (ldap_errno($this->ldapConnexion)) {
                throw new \Exception("LDAP error : " . $error);

            }
        }
    }

    /**
     *
     * @return string Next LDAP UID
     */
    public static function getNextUid()
    {

        $ldap = self::getConnexion();
        $maxUidNumber = \ConfigIgestisGlobalVars::minUidNumber();

        if (\ConfigIgestisGlobalVars::ldapAdMode() != true) {
            $result = $ldap->find("(uidNumber=*)", array("uidNumber"));

            try {
                foreach ($result as $dn => $entry) {
                    // For each entry
                    foreach ($entry as $attr => $values) {
                        // For each attribute
                        foreach ($values as $value) {
                            // For each value
                            if ($maxUidNumber < $value) {
                                $maxUidNumber = $value;
                            }

                        }
                    }
                }

                return $maxUidNumber + 1;
            } catch (\Exception $e) {
                if (\ConfigIgestisGlobalVars::debugMode()) {
                    new wizz($e->getMessage() . "\n" . $e->getTraceAsString());
                    return false;
                } else {
                    return false;
                }
            }
        } else {
            $result = $ldap->find("(objectSid=*)", array("objectSid"));

            try {
                foreach ($result as $dn => $entry) {
                    // For each entry
                    foreach ($entry as $attr => $values) {
                        // For each attribute
                        foreach ($values as $value) {
                            // For each value
                            $value = self::sidBinToString($value);
                            $exploded = explode("-", $value);
                            $value = $exploded[count($exploded) - 1];
                            if (count($exploded) != 8) {
                                continue;
                            }

                            if ($maxUidNumber < $value) {
                                $maxUidNumber = $value;
                            }

                        }
                    }
                }
                return $maxUidNumber + 1;
            } catch (\Exception $e) {
                if (\ConfigIgestisGlobalVars::debugMode()) {
                    new wizz($e->getMessage() . "\n" . $e->getTraceAsString());
                    return false;
                } else {
                    return false;
                }
            }
        }

    }

    /**
     * This function checks the validity of the session or logout in case
     * the password changed in the LDAP.
     *
     * @param \CoreContacts $contact
     * @return boolean
     */
    public static function ldapSynchDatas(\CoreContacts &$contact, $plainPassword)
    {
        if (!\ConfigIgestisGlobalVars::useLdap()) {
            return false;
        }

        switch ($contact->getUser()->getUserType()) {
            case "client":
                return true;
                break;
            case "employee":
                try {
                    $ldap = self::getConnexion($contact->getLogin(), $plainPassword, \ConfigIgestisGlobalVars::ldapUsersOu());

                    if (\ConfigIgestisGlobalVars::ldapAutoImportUser()) {
                        $nodesList = $ldap->find(str_replace("%u", $contact->getLogin(), \ConfigIgestisGlobalVars::ldapUserFilter()));
                        if (count($nodesList) < 1) {
                            return false;
                        }

                        $address = '';
                        $contact->setLastname('')
                            ->setFirstname('')
                            ->setEmail('')
                            ->setPhone1('')
                            ->setPhone2('')
                            ->setMobile('')
                            ->setPostalCode('')
                            ->setFax('')
                            ->setCity('')
                            ->setAddress1('')
                            ->setAddress2('');

                        foreach ($nodesList as $dn => $entry) {
                            // For each entry
                            foreach ($entry as $attr => $values) {
                                // For each attribute
                                foreach ($values as $value) {
                                    switch (strtolower($attr)) {
                                        case "sn":
                                            $contact->setLastname($value);
                                            break;
                                        case "givenname":
                                            $contact->setFirstname($value);
                                            break;
                                        case "objectsid":
                                            $value = self::sidBinToString($value);
                                            if (!trim($value)) {
                                                break;
                                            }

                                            $contact->setAdSid(trim($value));
                                            break;
                                        case "mail":
                                            $contact->setEmail($value);
                                            break;
                                        case "telephonenumber":
                                            $contact->setPhone1($value);
                                            break;
                                        case "homephone":
                                            $contact->setPhone2($value);
                                            break;
                                        case "mobile":
                                            $contact->setMobile($value);
                                            break;
                                        case "postalcode":
                                            $contact->setPostalCode($value);
                                            break;
                                        case "facsimiletelephonenumber":
                                            $contact->setFax($value);
                                            break;
                                        case "street":
                                            $address .= "\n" . $value;
                                            break;

                                        case "l":
                                            $contact->setCity($value);
                                            break;
                                    }
                                }
                            }
                        }
                        $value = explode("\n", trim($address));
                        if (!empty($value[0])) {
                            $contact->setAddress1(trim($value[0]));
                        }

                        if (!empty($value[1])) {
                            $contact->setAddress2(trim($value[1]));
                        }

                    }

                } catch (\Exception $e) {
                    if (\ConfigIgestisGlobalVars::debugMode()) {
                        new \wizz($e->getMessage() . "\n" . $e->getTraceAsString());
                        return false;
                    } else {
                        return false;
                    }
                }
                $contact->setPassword($plainPassword);
                return true;
                break;

            default:
                return false;
                break;
        }
        return false;
    }

    /**
     * Get the binary format of the SID and return it into a readable string
     * @param string $binsid Binary data returned by Active directory
     * @return string Readable string
     */
    final public static function sidBinToString($binsid)
    {
        $hex_sid = bin2hex($binsid);
        $rev = hexdec(substr($hex_sid, 0, 2));
        $subcount = hexdec(substr($hex_sid, 2, 2));
        $auth = hexdec(substr($hex_sid, 4, 12));
        $result = "$rev-$auth";

        for ($x = 0; $x < $subcount; $x++) {
            $subauth[$x] =
                hexdec(self::littleEndian(substr($hex_sid, 16 + ($x * 8), 8)));
            $result .= "-" . $subauth[$x];
        }

        // Cheat by tacking on the S-
        return 'S-' . $result;
    }

    /**
     * Converts a little-endian hex-number to one, that 'hexdec' can convert
     * @param string $hex
     * @return string
     */
    final protected static function littleEndian($hex)
    {
        for ($x = strlen($hex) - 2; $x >= 0; $x = $x - 2) {
            $result .= substr($hex, $x, 2);
        }
        return $result;
    }

    public static function importUser($login, $plainPassword)
    {
        $entityManager = \Application::getEntityManager();
        if (!\ConfigIgestisGlobalVars::useLdap()) {
            return true;
        }

        try {

            $ldap = self::getConnexion($login, $plainPassword, \ConfigIgestisGlobalVars::ldapUsersOu());

            $nodesList = $ldap->find(str_replace("%u", $login, \ConfigIgestisGlobalVars::ldapUserFilter()));
            if (count($nodesList) < 1) {
                return false;
            }

            $employee = \CoreUsers::newEmployee();
            $contact = new \CoreContacts();
            $contact->setMainContact(true);
            $contact->disablePostPersistProcess();

            foreach ($nodesList as $dn => $entry) {
                // For each entry
                foreach ($entry as $attr => $values) {
                    // For each attribute
                    foreach ($values as $value) {
                        switch (strtolower($attr)) {
                            case "sn":
                                $contact->setLastname($value);
                                break;
                            case "givenname":
                                $contact->setFirstname($value);
                                break;
                            case "objectsid":
                                $value = self::sidBinToString($value);
                                if (!trim($value)) {
                                    break;
                                }

                                $contact->setAdSid(trim($value));
                                break;
                        }
                    }
                }
            }

            $contact->setLogin($login)->setPassword($plainPassword)->disablePostPersistProcess();
            $employee->setUserLabel($contact->getFirstName() . " " . $contact->getLastName());
            $employee->AddOrEditContact($contact);

            $company = $entityManager->getRepository("\CoreCompanies")->getFirst();
            if (!$company) {
                throw new \Exception(_("There is not any company to import the user in"));
            }

            $employee->setCompany($company);

            $entityManager->persist($employee);
            $entityManager->flush();

        } catch (\Exception $exc) {
            \IgestisErrors::createWizz($exc, \IgestisErrors::TYPE_ANY, _("Unable to retrieve datas from the LDAP / Active directory server"));
            return false;
        }
    }

    public static function createCn($cn)
    {

        try {
            $ldap = self::getConnexion();

            $count = 0;
            $currentCn = $cn;
            do {
                if ($count == 1) {
                    $count++;
                }

                if ($count > 0) {
                    $currentCn .= " " . $count;
                }

                $result = $ldap->find("(cn=$currentCn)", array("cn"));
                $count++;
            } while (count($result) > 0);

            return $currentCn;

        } catch (\Exception $exc) {
            throw $exc;
        }

    }

}
