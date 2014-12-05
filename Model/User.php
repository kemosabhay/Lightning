<?php

namespace Overridable\Lightning\Model;

use Lightning\Tools\ClientUser;
use Lightning\Tools\Configuration;
use Lightning\Tools\Database;
use Lightning\Tools\Logger;
use Lightning\Tools\Mailer;
use Lightning\Tools\Messenger;
use Lightning\Tools\Navigation;
use Lightning\Tools\Security\Encryption;
use Lightning\Tools\Security\Random;
use Lightning\Tools\Request;
use Lightning\Tools\Scrub;
use Lightning\Tools\Session;
use Lightning\Tools\Tracker;
use Lightning\View\Field\Time;

class User {
    /**
     * A default user with no priviliges.
     */
    const TYPE_UNREGISTERED_USER = 0;

    /**
     * A registered user with a confirmed status.
     */
    const TYPE_REGISTERED_USER = 1;

    /**
     * An admin user with all access.
     */
    const TYPE_ADMIN = 5;

    const TEMP_KEY_TTL = 86400;

    /**
     * The row from the database.
     *
     * @var array
     */
    protected $details;

    /**
     * Instantiate a user object with it's data row.
     *
     * @param array $details
     *   The user's data row from the database.
     */
    public function __construct($details) {
        $this->details = $details;
    }

    public function __isset($var) {
        switch($var) {
            case 'id':
            case 'details':
                return true;
                break;
            default:
                return isset($this->details[$var]);
                break;
        }
    }

    /**
     * A getter function.
     *
     * This works for:
     *   ->id
     *   ->details
     *   ->user_id (item inside ->details)
     *
     * @param string $var
     *   The name of the requested variable.
     *
     * @return mixed
     *   The variable value.
     */
    public function __get($var) {
        switch($var) {
            case 'id':
                return $this->details['user_id'];
                break;
            case 'details':
                return $this->details;
                break;
            default:
                if(isset($this->details[$var]))
                    return $this->details[$var];
                else
                    return NULL;
                break;
        }
    }

    /**
     * A setter function.
     *
     * This works for:
     *   ->id
     *   ->details
     *   ->user_id (item inside ->details)
     *
     * @param string $var
     *   The name of the variable to set.
     * @param mixed $value
     *   The value to set.
     *
     * @return mixed
     *   The variable value.
     */
    public function __set($var, $value) {
        switch($var) {
            case 'id':
                $this->details['user_id'] = $value;
                break;
            case 'details':
                $this->details = $value;
                break;
            default:
                $this->details[$var] = $value;
                break;
        }
    }

    /**
     * Load a user by their email.
     *
     * @param $email
     * @return bool|User
     */
    public static function loadByEmail($email) {
        if($details = Database::getInstance()->selectRow('user', array('email' => array('LIKE', $email)))) {
            return new self($details);
        }
        return false;
    }

    /**
     * Load a user by their ID.
     *
     * @param $user_id
     * @return bool|User
     */
    public static function loadById($user_id) {
        if($details = Database::getInstance()->selectRow('user', array('user_id' => $user_id))) {
            return new static($details);
        }
        return false;
    }

    /**
     * Load a user by their temporary access key, from a password reset link.
     *
     * @param string $key
     *   A temporary access key.
     * @return bool|User
     */
    public static function loadByTempKey($key) {
        if ($details = Database::getInstance()->selectRow(
            array(
                'from' => 'user_temp_key',
                'join' => array(
                    'LEFT JOIN',
                    'user',
                    'using (`user_id`)',
                )
            ),
            array(
                'temp_key' => $key,
                // The key is only good for 24 hours.
                'time' => array('>=', time() - static::TEMP_KEY_TTL),
            )
        )) {
            return new static ($details);
        }
        return false;
    }

    public function update($values) {
        $this->details = $values + $this->details;
        Database::getInstance()->update('user', $values, array('user_id' => $this->id));
    }

    /**
     * Create a new anonymous user.
     *
     * @return User
     */
    public static function anonymous() {
        return new static(array('user_id' => 0));
    }

    /**
     * Check if a user is a site admin.
     *
     * @return boolean
     *   Whether the user is a site admin.
     */
    public function isAdmin() {
        return $this->type == static::TYPE_ADMIN;
    }

    /**
     * Check if a user is anonymous.
     *
     * @return boolean
     *   Whether the user is anonymous.
     */
    public function isAnonymous() {
        return $this->user_id == 0;
    }

    /**
     * Assign a new user type to this user.
     *
     * @param integer $type
     *   The new type.
     */
    public function setType($type) {
        $this->details['type'] = $type;
        Database::getInstance()->update('user', array('type' => $type), array('user_id' => $this->id));
    }

    /**
     * Change the user's active status.
     *
     * @param integer $new_value
     *   The new active status.
     */
    public function setActive($new_value) {
        $this->details['active'] = $new_value;
        Database::getInstance()->update('user', array('active' => $new_value), array('user_id' => $this->id));
    }

    /**
     * Check if the supplied password is correct.
     *
     * @param string $pass
     *   The supplied password.
     * @param string $salt
     *   The salt from the database.
     * @param string $hashed_pass
     *   The hashed password from the database.
     *
     * @return boolean
     *   Whether the correct password was supplied.
     */
    public function checkPass($pass, $salt = '', $hashed_pass = '') {
        if($salt == '') {
            $this->load_info();
            $salt = $this->details['salt'];
            $hashed_pass = $this->details['password'];
        }
        if ($hashed_pass == $this->passHash($pass, pack("H*",$salt))) {
            return true;
        }
        else {
            return false;
        }
    }

    /**
     * Create a password hash from a password and salt.
     *
     * @param string $pass
     *   The password.
     * @param string $salt
     *   The salt.
     *
     * @return string
     *   The hashed password.
     */
    public static function passHash($pass, $salt) {
        return hash("sha256", $pass . $salt);
    }

    /**
     * Get a new salt string.
     *
     * @return string
     *   A binary string of salt.
     */
    public static function getSalt() {
        return Random::getInstance()->get(32, Random::BIN);
    }

    public static function urlKey($user_id = -1, $salt = null) {
        if($user_id == -1) {
            $user_id = ClientUser::getInstance()->id;
            $salt = ClientUser::getInstance()->details['salt'];
        } elseif (!$salt) {
            $user = Database::getInstance()->selectRow('user', array('user_id' => $user_id));
            $salt = $user['salt'];
        }
        // TODO: This should be stronger.
        return $user_id . "." . static::passHash($user_id . $salt, $salt);
    }

    /**
     * Update the user's last active time.
     *
     * This should happen on each page load.
     */
    public function ping() {
        Database::getInstance()->update('user', array('last_active' => time()), array('user_id' => $this->id));
    }

    /**
     * Reload the user's info from the database.
     *
     * @param boolean $force
     *   Whether to force the data to load and overwrite current data.
     */
    public function load_info($force = false) {
        if(!isset($this->details) || $force) {
            $this->details = Database::getInstance()->selectRow('user', array('user_id' => $this->id));
        }
    }

    /**
     * Create a new user.
     *
     * @param string $email
     *   The user's email address.
     * @param string $pass
     *   The new password.
     *
     * @return boolean
     *   Whether the user could be created.
     */
    public static function create($email, $pass) {
        if (Database::getInstance()->check('user', array('email' => strtolower($email), 'password' => array('!=', '')))) {
            // An account already exists with that email.
            Messenger::error('An account with that email already exists. Please try again. if you lost your password, click <a href="/user?action=reset&email=' . urlencode($email) . '">here</a>');
            return false;
        } elseif ($user_info = Database::getInstance()->selectRow('user', array('email' => strtolower($email), 'password' => ''))) {
            // EMAIL EXISTS IN MAILING LIST ONLY
            $updates = array();
            if ($user_info['confirmed'] != 0) {
                $updates['confirmed'] = rand(100000,9999999);
            }
            if ($ref = Request::cookie('ref', 'int')) {
                $updates['referrer'] = $ref;
            }
            $user = new self($user_info['user_id']);
            $user->setPass($pass, '', $user_info['user_id']);
            $updates['register_date'] = Time::today();
            Database::getInstance()->update('user', $updates, array('user_id' => $user_info['user_id']));
            if($user_info['confirmed'] != 0 && Configuration::get('user.requires_confirmation')) {
                $user->sendConfirmationEmail($email);
            }
            return $user_info['user_id'];
        } else {
            // EMAIL IS NOT IN MAILING LIST AT ALL
            $user_id = static::insertUser($email, $pass);
            $updates = array();
            if ($ref = Request::cookie('ref', 'int')) {
                $updates['referrer'] = $ref;
            }
            $updates['confirmed'] = rand(100000,9999999);
            $updates['type'] = 1;
            Database::getInstance()->update('user', $updates, array('user_id' => $user_id));
            $user = new self($user_id);
            if (Configuration::get('user.requires_confirmation')) {
                $user->sendConfirmationEmail($email);
            }
            return $user_id;
        }
    }

    /**
     * Make sure that a user's email is listed in the database.
     *
     * @param string $email
     *   The user's email.
     * @param array $options
     *   Additional values to insert.
     * @param array $update
     *   Which values to update the user if the email already exists.
     *
     * @return User
     */
    public static function addUser($email, $options = array(), $update = array()) {
        $user_data = array();
        $user_data['email'] = strtolower($email);
        $db = Database::getInstance();
        if ($user = $db->selectRow('user', $user_data)) {
            if($update) {
                if (!isset($update['list_date'])) {
                    $update['list_date'] = time();
                }
                $db->update('user', $user_data, $update);
            }
            $user_id = $user['user_id'];
            return static::loadById($user_id);
        } else {
            $user_data['list_date'] = time();
            $user_id = $db->insert('user', $options + $user_data);
            $user = static::loadById($user_id);
            $user->new = true;
            return $user;
        }
    }

    /**
     * Add the user to the mailing list.
     *
     * @param $list_id
     *   The ID of the mailing list.
     */
    public function subscribe($list_id = 0) {
        Database::getInstance()->insert('message_list_user', array('message_list_id' => $list_id, 'user_id' => $this->id), true);
        Tracker::trackEvent('Subscribe', $list_id, $this->id);
    }

    /**
     * Create a new random password.
     *
     * @return string
     *   A random password.
     */
    public function randomPass() {
        $alphabet = "abcdefghijkmnpqrstuvwxyz";
        $arrangement = "aaaaaaaAAAAnnnnn";
        $pass = "";
        for($i = 0; $i < strlen($arrangement); $i++) {
            if($arrangement[$i] == "a")
                $char = $alphabet[rand(0,25)];
            else if($arrangement[$i] == "A")
                $char = strtoupper($alphabet[rand(0,(strlen($alphabet)-1))]);
            else if($arrangement[$i] == "n")
                $char = rand(0,9);
            if(rand(0,1) == 0)
                $pass .= $char;
            else
                $pass = $char.$pass;
        }
        return $pass;
    }

    /**
     * Insert a new user if he doesn't already exist.
     *
     * @param string $email
     *   The new email
     * @param string $pass
     *   The new password
     * @param string $first_name
     *   The first name
     * @param string $last_name
     *   The last name.
     *
     * @return integer
     *   The new user's ID.
     */
    public static function insertUser($email, $pass = NULL, $first_name = '', $last_name = '') {
        $user_details = array(
            'email' => Scrub::email(strtolower($email)),
            'first' => $first_name,
            'last' => $last_name,
            'register_date' => Time::today(),
            'confirmed' => rand(100000,9999999),
            'type' => 0,
            // TODO: Need to get the referrer id.
            'referrer' => 0,
        );
        if ($pass) {
            $salt = static::getSalt();
            $user_details['password'] = static::passHash($pass, $salt);
            $user_details['salt'] = bin2hex($salt);
        }
        return Database::getInstance()->insert('user', $user_details);
    }

    /**
     * Update a user's password.
     *
     * @param string $pass
     *   The new password.
     * @param string $email
     *   Their email if updating by email.
     * @param integer $user_id
     *   The user_id if updating by user_id.
     *
     * @return boolean
     *   Whether the password was updated.
     */
    public function setPass($pass, $email='', $user_id = 0) {
        if($email != '') {
            $where['email'] = strtolower($email);
        } elseif($user_id>0) {
            $where['user_id'] = $user_id;
        } else {
            $where['user_id'] = $this->id;
        }

        $salt = $this->getSalt();
        return (boolean) Database::getInstance()->update(
            'user',
            array(
                'password' => $this->passHash($pass,$salt),
                'salt' => bin2hex($salt),
            ),
            $where
        );
    }

    public function admin_create($email, $first_name='', $last_name='') {
        $today = gregoriantojd(date('m'), date('d'), date('Y'));
        $user_info = Database::getInstance()->selectRow('user', array('email' => strtolower($email)));
        if($user_info['password'] != '') {
            // user exists with password
            // return user_id
            return $user_info['user_id'];
        } else if(isset($user_info['password'])) {
            // user exists without password
            // set password, send email
            $randomPass = $this->randomPass();
            $this->setPass($randomPass, $email);
            $mailer = new Mailer();
            $mailer->to($email)->subject('New Account')->message("Your account has been created with a temporary password. Your temporary password is: {$randomPass}\n\nTo reset your password, log in with your temporary password and click 'my profile'. Follow the instructions to reset your new password.");
            Database::getInstance()->update(
                'user',
                array(
                    'register_date' => $today,
                    'confirmed' => rand(100000,9999999),
                    'type' => 1,
                ),
                array(
                    'user_id' => $user_info['user_id'],
                )
            );
            return $user_info['user_id'];
        } else {
            // user does not exist
            // create user with random password, send email to activate
            $randomPass = $this->randomPass();
            $user_id = $this->insertUser($email, $randomPass, $first_name, $last_name);
            $mailer = new Mailer();
            $mailer->to($email)->subject('New Account')->message("Your account has been created with a temporary password. Your temporary password is: {$randomPass}\n\nTo reset your password, log in with your temporary password and click 'my profile'. Follow the instructions to reset your new password.");
            Database::getInstance()->update(
                'user',
                array(
                    'register_date' => $today,
                    'confirmed' => rand(100000,9999999),
                    'type' => 1,
                ),
                array(
                    'user_id' => $user_id,
                )
            );
            return $user_id;
        }

    }

    public static function add_to_mailing_list($email) {
        // These will be set on either insert or update.
        $user_values = array(
            'list_date' => Time::today(),
            'active' => 1,
        );
        $user_id = Database::getInstance()->insert(
            'user',
            array_merge($user_values,
                array(
                    'email' => strtolower($email),
                    // Ref should only be added for new users.
                    'referrer' => Request::cookie('ref') ?: 0,
                )
            ),
            $user_values
        );
        return $user_id;
    }

    public function fullName() {
        return $this->details['first'] . ' ' . $this->details['last'];
    }

    /**
     * Send a new random password via email.
     */
    public function sendResetLink() {
        // Create a temporary key.
        $reset_key = base64_encode($this->getSalt());
        Database::getInstance()->insert(
            'user_temp_key',
            array(
                'user_id' => $this->id,
                'temp_key' => $reset_key,
                'time' => time(),
            ),
            array(
                'temp_key' => $reset_key,
                'time' => time(),
            )
        );

        // Send a message.
        $mailer = new Mailer();
        $mailer->to($this->details['email'], $this->fullName())
            ->subject('Password reset')
            ->message('A request was made to reset your password. If you did not make this request, please <a href="' . Configuration::get('web_root') . '/contact' . '">notify us</a>. To reset your password, <a href="' . Configuration::get('web_root') . '/user?action=set-password&key=' . $reset_key . '">click here</a>.');
        return $mailer->send();
    }

    /**
     * Delete the temoporary password reset key.
     */
    public function removeTempKey() {
        Database::getInstance()->delete(
            'user_temp_key',
            array(
                'user_id' => $this->id,
            )
        );
    }

    public static function removeExpiredTempKeys() {
        return Database::getInstance()->delete(
            'user_temp_key',
            array(
                'time' => array('<', time() - static::TEMP_KEY_TTL)
            )
        );
    }

    public static function find_by_email($email) {
        return Database::getInstance()->selectRow('user', array('email' => strtolower($email)));
    }

    /**
     * Makes sure there is a session, and checks the user password.
     * If everything checks out, the global user is created.
     *
     * @param $email
     * @param $password
     * @param bool $remember
     *   If true, the cookie will be permanent, but the password and pin state will still be on a timeout.
     * @param boolean $auth_only
     *   If true, the user will be authenticated but will not have the password state set.
     *
     * @return bool
     */
    public static function login($email, $password, $remember = FALSE, $auth_only = FALSE) {
        // If $auth_only is set, it has to be remembered.
        if ($auth_only) {
            $remember = TRUE;
        }

        $user = ClientUser::getInstance();

        // If a user is already logged in, cancel that user.
        if($user->id > 0) {
            $user->destroy();
        }

        if($temp_user = static::loadByEmail($email)) {
            // user found
            if($temp_user->checkPass($password)) {
                $temp_user->registerToSession($remember, $auth_only ?: Session::STATE_PASSWORD);
                return true;
            } else {
                Logger::logIP('Bad Password', Logger::SEVERITY_HIGH);
            }
        } else {
            Logger::logIP('Bad Username', Logger::SEVERITY_MED);
        }
        // Could not log in.
        return false;
    }

    public function destroy() {
        // TODO: Remove the current user's session.
        Session::reset();
    }

    public function registerToSession($remember = false, $state = Session::STATE_PASSWORD) {
        // We need to create a new session if:
        //  There is no session
        //  The session is blank
        //  The session user is not set to this user
        $session = Session::getInstance(false);
        if((!is_object($session)) || ($session->id == 0) || ($session->user_id != $this->id && $session->user_id != 0)) {
            if(is_object($session)) {
                // If there is some other session here, we can destroy it.
                $session->destroy();
            }
            $session = Session::create($this->id, $remember);
            Session::setInstance($session);
        }
        if ($session->user_id == 0) {
            $session->setUser($this->id);
        }
        if ($state) {
            $session->setState($state);
        }
        ClientUser::setInstance($this);
    }

    /**
     * Destroy a user object and end the session.
     */
    public function logOut() {
        $session = Session::getInstance();
        if($this->id > 0) {
            $this->details = NULL;
            $this->id = 0;
            if(is_object($session)) {
                $session->destroy();
            }
        }
    }

    public function reset_code($email) {
        $acct_details = user::find_by_email($email);
        return hash('sha256',($acct_details['email']."*".$acct_details['password']."%".$acct_details['user_id']));
    }

    /**
     * Get a link to unsubscribe this user.
     *
     * @return string
     *   The absolute web url.
     */
    public function getUnsubscribeLink() {
        return Configuration::get('web_root')
            . '/user?action=unsubscribe&u=' . $this->getEncryptedUserReference();
    }

    /**
     * Get this users encrypted email.
     *
     * @return string
     *   The encrypted email reference.
     */
    public function getEncryptedUserReference() {
        return Encryption::aesEncrypt($this->details['email'], Configuration::get('user.key'));
    }

    /**
     * Load a user by an encrypted reference.
     *
     * @param string $cypher_string
     *   The encrypted email address.
     *
     * @return bool|User
     *   The user if loading was successful.
     */
    public static function loadByEncryptedUserReference($cypher_string) {
        $email = Encryption::aesDecrypt($cypher_string, Configuration::get('user.key'));
        return static::loadByEmail($email);
    }

    /**
     * Redirects the user if they are not logged in.
     *
     * @param int $auth
     *   A required authority level if they are logged in.
     */
    public function login_required($auth = 0) {
        if($this->id == 0) {
            Navigation::redirect($this->login_url . urlencode($_SERVER['REQUEST_URI']));
        }
        if($this->authority < $auth) {
            Navigation::redirect($this->unauthorized_url . urlencode($_SERVER['REQUEST_URI']));
        }
    }

    public function sendConfirmationEmail($email) {
        $acct_details = user::find_by_email($email);
        if($acct_details['confirmed'] == "" || $acct_details['confirmed'] == "confirmed") {
            $acct_details['confirmed'] = hash('sha256',microtime());
            Database::getInstance()->update('user',
                array('confirmed' => $acct_details['confirmed']),
                array('user_id' => $acct_details['user_id'])
            );
        }
        global $mail_site_name,$email_domain_name,$site_contact_page;
        $mailer = new Mailer();
        $mailer->to($email, $acct_details['first']." ".$acct_details['last'])
            ->subject('Activate your account')
            ->message("You new account has been created. To activate your account, <a href='http://{$email_domain_name}/user.php?confirm={$acct_details['user_id']}.{$acct_details['confirmed']}'>click here</a> or copy and paste this link into your browser:<br /><br />
	http://{$email_domain_name}/user.php?confirm={$acct_details['user_id']}.{$acct_details['confirmed']}
	<br /><br /> If you did not open an account with {$mail_site_name}, please let us know by contacting us at http://{$email_domain_name}/{$site_contact_page}")
            ->send();
    }

    /**
     * When a user logs in to an existing account from a temporary anonymous session, this
     * moves the data over to the user's account.
     *
     * @param $anon_user
     */
    public function merge_users($anon_user) {
        // FIRST MAKE SURE THIS USER IS ANONYMOUS
        if(Database::getInstance()->check('user', array('user_id' => $anon_user, 'email' => ''))) {
            // TODO: Basic information should be moved here, but this function should be overriden.
            Database::getInstance()->delete('user', array('user_id' => $anon_user));
        }
    }
}
