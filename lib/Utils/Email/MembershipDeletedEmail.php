<?php
/**
 * Created by PhpStorm.
 * User: fregini
 * Date: 16/02/2017
 * Time: 15:35
 */

namespace Email;


use Organizations\OrganizationStruct;

class MembershipDeletedEmail extends AbstractEmail {

    protected $title ;

    /**
     * @var \Users_UserStruct
     */
    protected $user ;
    /**
     * @var \Users_UserStruct
     */
    protected $sender ;

    /**
     * @var OrganizationStruct
     */
    protected $organization ;

    /**
     * MembershipDeletedEmail constructor.
     * @param \Users_UserStruct $sender
     * @param \Users_UserStruct $removed_user
     * @param OrganizationStruct $organization
     */
    public function __construct( \Users_UserStruct $sender, \Users_UserStruct $removed_user , OrganizationStruct $organization) {
        $this->user = $removed_user ;
        $this->_setLayout('skeleton.html');
        $this->_setTemplate('Organization/membership_deleted_content.html');

        $this->sender = $sender ;
        $this->title = "You've been removed from organization " . $organization->name ;
        $this->organization = $organization ;
    }

    protected function _getTemplateVariables()
    {
        return array(
            'user'           => $this->user->toArray(),
            'sender'         => $this->sender->toArray(),
            'organization'   => $this->organization->toArray()
        );
    }

    protected function _getLayoutVariables()
    {
        $vars = parent::_getLayoutVariables();
        $vars['title'] = $this->title ;

        return $vars ;
    }

    protected function _getDefaultMailConf()
    {
        return parent::_getDefaultMailConf(); // TODO: Change the autogenerated stub
    }


    public function send() {
        $recipient  = array( $this->user->email, $this->user->fullName() );

        $this->doSend( $recipient, $this->title ,
            $this->_buildHTMLMessage(),
            $this->_buildTxtMessage( $this->_buildMessageContent() )
        );
    }

}
