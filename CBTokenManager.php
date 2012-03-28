<?php if (!defined('TL_ROOT')) die('You can not access this file directly!');

/**
 * Contao Open Source CMS
 * Copyright (C) 2005-2011 Leo Feyer
 *
 * This program is free software: you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation, either
 * version 3 of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this program. If not, please visit the Free
 * Software Foundation website at <http://www.gnu.org/licenses/>.
 *
 * PHP version 5
 * @copyright  Winans Creative 2011, Helmut Schottmôller 2009
 * @author     Blair Winans <blair@winanscreative.com>
 * @author     Fred Bliss <fred.bliss@intelligentspark.com>
 * @author     Adam Fisher <adam@winanscreative.com>
 * @author     Includes code from survey_ce module from Helmut Schottmôller <typolight@aurealis.de>
 * @license    http://opensource.org/licenses/lgpl-3.0.html
 */

/**
 * Handle token payments
 *
 * @extends Controller
 */
class CBTokenManager extends Controller
{
	/**
	 * Email Template
	 */
	protected $strEmailTemplate = 'cb_tokenusageemail';
	
	/**
	 * Load Frontend Classes
	 */
	public function __construct()
	{
		parent::__construct();
		$this->import('Isotope');
		$this->import('Database');
		$this->import('FrontendUser', 'User');
	}


	/**
	 * Callback for writeTokens Hook. Adds the courseid to the insert array
	 */
	public function writeTokens( $arrSet )
	{		
		foreach( $arrSet as $token=>$set )
		{
			$intProduct = $set['product_id'];
					
			$objProduct = $this->Database->prepare("SELECT courseid FROM tl_iso_products WHERE id=?")->limit(1)->execute( $intProduct );
			
			if( $objProduct->courseid )
			{
				$arrSet[$token]['courseid'] = $objProduct->courseid;
			}
		}
		return $arrSet;
	}
	
	
	/**
	 * Callback for postCheckout Hook. Sets orders with token payments to 'complete' status.
	 */
	public function postCheckout( IsotopeOrder $objOrder, $arrItemIds )
	{
		
		// Check the payment type of the order
		$objPaymentType = $this->Database->prepare("SELECT DISTINCT pm.type FROM `tl_iso_orders` o LEFT OUTER JOIN `tl_iso_payment_modules` pm ON o.payment_id = pm.id WHERE o.id=?")
										 ->execute($objOrder->id);
										 
		// Check the 'tokens' field on the order
		$arrTokens = deserialize( $objOrder->tokens, true );
										 
		// If the member used a token, set the order status to 'complete'
		if (($objPaymentType->numRows > 0 && $objPaymentType->type == 'token') || count($arrTokens))
		{
			$objOrder->new_order_status = 'complete';
			$objOrder->status = 'complete';
			$objOrder->save();
		}		
	}
	
	
	/**
	 * Callback for addMemberClient Hook. Assigns the parent ID by the token used
	 */
	public function addMemberClient( $intParentID, $objOrder, $objProduct )
	{
		$intOriginalID = $intParentID;
		$arrTokens = deserialize( $objOrder->tokens, true );
		
		if( count($arrTokens) )
		{
			if( count($arrTokens)==1 ) // Easy enough to do one token. Find the parent
			{
				$intParentID = $this->Database->prepare("SELECT pid FROM tl_iso_tokens WHERE token=?")->limit(1)->execute( $arrTokens[0] )->pid;				
			}
			else
			{
				//@todo - create two children
			}
		
		}
		
		return $intParentID;
		
	}
	
	
	/**
	 * Callback for beginCourse Hook. Deducts one token credit from parents users if they try to take the course again.
	 */
	public function startCourse( $objTemplate, $objCourse )
	{
		
		$objPastData = $this->Database->prepare("SELECT * FROM tl_cb_coursedata WHERE pid=? AND courseid=? AND status<>'archived' AND status<>'ready'")
										  ->execute($this->User->id, $objCourse->id);
										  
		if(!$objPastData->numRows)
		{
			//This is the first attempt so we deduct a token if there is one and this is the parent purchaser
			$objToken = $this->Database->prepare("SELECT * FROM tl_iso_tokens WHERE pid=? AND courseid=?")
										  ->limit(1)
										  ->execute($this->User->id, $objCourse->id);
										  
			if($objToken->numRows && (int)$objToken->credits > 0 )
			{
				$intNewCredits = (int)$objToken->credits - 1;
				$this->Database->prepare("UPDATE tl_iso_tokens SET credits=? WHERE pid=? AND courseid=?")
										  ->execute($intNewCredits, $this->User->id, $objCourse->id);
			}
			elseif( $objToken->numRows && (int)$objToken->credits == 0  )
			{
				//@todo - trigger error and return error template
			}
		}
		
		$this->sendTokenUsageEmail($objCourse); //Send email if needed
		
		return $objTemplate;
	}
	
	
	/**
	 * Callback for cb_getClientResults Hook. Makes sure data pertains to the current member
	 */
	public function filterMemberClientData($arrResults, $objModule)
	{
		if (count($arrResults))
		{
			$arrMemberClients = $this->Database->prepare("SELECT DISTINCT `id` FROM `tl_cb_member_clients` WHERE `pid` = ?")->execute($this->User->id)->fetchEach('id');
			
			if (count($arrMemberClients))
			{
				foreach ($arrResults as $key=>$result)
				{
					if (!in_array($result['clientid'], $arrMemberClients))
						unset($arrResults[$key]);
				}
			}
		}
		
		return $arrResults;
	}

	
	/**
	 * Callback for postAddMemberClient hook. Sets the original course data so that we know how they paid for it
	 */
	public function setInitialCourseData( $intParentID, $intClientID, $objOrder, $objProduct )
	{
		
		$objPastData = $this->Database->prepare("SELECT * FROM tl_cb_coursedata WHERE pid=? AND courseid=? AND status<>'in_progress' AND status<>'review' AND pass<>1")
										  ->execute($this->User->id, $objProduct->courseid);
										  
		$intNewAttempt = (int)$objPastData->numRows + 1;
		
		//Need to generate a new record for this course
		$arrSet = array(
			'pid'		=> $this->User->id,
			'tstamp'	=> time(),
			'session'	=> '',
			'courseid'	=> $objProduct->courseid,
			'status' 	=> 'ready',
			'settings'	=> array(),
			'attempt'	=> $intNewAttempt,
			'orderid'	=> $objOrder->id,
			'clientid'	=> $intClientID,
		);
  				
  		$intDataId = $this->Database->prepare("INSERT INTO tl_cb_coursedata %s")->set($arrSet)->execute()->insertId;
	}

	
	/**
	 * Callback for insertCourseData hook. Sets the any new course data rows so that we know how they paid for it
	 */
	public function setCourseDataInsertRow($arrSet, $objCourse, $objCourseBuilder)
	{
		// Using try/catch since this is live...
		try
		{
			if (!array_key_exists('orderid', $arrSet) && !array_key_exists('clientid', $arrSet))
			{
				$strSQL = "";
				
				// Look for a row that was updated by setInitialCourseData
				$objResult = $this->Database->prepare("SELECT * FROM tl_cb_coursedata WHERE pid=? AND courseid=? AND status = 'skipped' AND IFNULL(clientid, '') <> '' AND IFNULL(orderid, '') <> ''")
									  		 ->limit(1)
									  		 ->executeUncached($this->User->id, $arrSet['courseid']);
				
				if ($objResult->numRows)
				{
					$arrSet['clientid'] = $objResult->clientid;
					$arrSet['orderid'] = $objResult->orderid;
				}
			}
		}
		catch (Exception $e)
		{
			$this->log('Error -- ' . $e->getMessage(), __METHOD__, TL_ERROR);
		}
		
		return $arrSet;
	}
	
	
	/**
	 * Callback for cb_generateCourse hook. Send an email to the parent user every time a token is used for the first time.
	 */
	public function sendTokenUsageEmail($objCourse)
	{	
		// Get course data row
		$objCurrentData = $this->Database->prepare("SELECT * FROM tl_cb_coursedata WHERE pid=? AND courseid=? AND status='ready'")
												->limit(1)
											 	->execute($this->User->id, $objCourse->id);											 	
											 	
		if ($objCurrentData->numRows)
		{	
			// Increment the attempt and set the status to 'in_progress'
			$intNewAttempt = ((int)$objCurrentData->attempt == 0) ? (int)$objCurrentData->attempt + 1 : (int)$objCurrentData->attempt;
			
			$this->Database->prepare("UPDATE tl_cb_coursedata SET attempt=?, status='in_progress' WHERE id=?")
							->execute($intNewAttempt, $objCurrentData->id);
			
			
			// Get member/client relation data
			$objClientMemberID = $this->Database->prepare("SELECT pid, clientid FROM tl_cb_member_clients WHERE id=?")
												->limit(1)
												->execute($objCurrentData->clientid);
			
			// Get course product data
			$objProductData = $this->Database->prepare("SELECT * FROM tl_iso_products WHERE courseid=?")
												->limit(1)
												 ->execute($objCourse->id);
												 
			if ($objClientMemberID->numRows > 0 && $objProductData->numRows > 0)
			{			
				// Get email data		
				$strSQL = "SELECT DISTINCT c.name AS `course_name`,
								m.email AS `parent_email`,
								m.firstname AS `parent_firstname`,
								m.lastname AS `parent_lastname`,
								mc.firstname AS `child_firstname`,
								mc.lastname AS `child_lastname`,
								mc.company AS `child_company`,
								mc.street AS `child_street`,
								mc.postal AS `child_postal`,
								mc.city AS `child_city`,
								mc.state AS `child_state`,
								mc.country AS `child_country`,
								mc.phone AS `child_phone`,
								mc.email AS `child_email`,
								t.token,
								tu.tstamp AS `token_date`,
								(SELECT SUM(mcourses.usedattempts) FROM `tl_cb_member_clients` mclients 
								INNER JOIN `tl_cb_member_courses` mcourses ON mclients.id = mcourses.pid WHERE mclients.pid = m.id AND mcourses.courseid = c.id) AS `credits_used`,
								t.credits - (SELECT SUM(mcourses.usedattempts) FROM `tl_cb_member_clients` mclients 
								INNER JOIN `tl_cb_member_courses` mcourses ON mclients.id = mcourses.pid WHERE mclients.pid = m.id AND mcourses.courseid = c.id) AS `credits_remaining`
							FROM `tl_iso_tokens` t
							INNER JOIN `tl_iso_tokens_usage` tu
								ON t.id = tu.pid
								AND tu.memberid = ?
							INNER JOIN `tl_member` m
								ON t.pid = m.id
							INNER JOIN `tl_member` mc
								ON tu.memberid = mc.id
							INNER JOIN `tl_iso_products` p
								ON t.product_id = p.id
							INNER JOIN `tl_cb_course` c
								ON p.courseid = c.id
							LEFT OUTER JOIN `tl_cb_member_clients` mcl
								ON mc.id = mcl.clientid
							LEFT OUTER JOIN `tl_cb_member_courses` mco
								ON mcl.clientid = mco.pid
							WHERE t.pid = ?
								AND t.product_id = ?
								AND tu.orderid = ?";
				
				$arrDbData = $this->Database->prepare($strSQL)
											->limit(1)
											->execute($objClientMemberID->clientid, $objClientMemberID->pid, $objProductData->id, $objCurrentData->orderid)->row();
																			
											
				if (count($arrDbData) && strlen($arrDbData['parent_email']))
				{			
					try
					{			
						$arrPlainData = array_map('strip_tags', $arrDbData);
						$objHtmlTemplate = new IsotopeTemplate($this->strEmailTemplate);
				
						$arrCountry = (explode('-', $arrPlainData['child_state']));
						
						// Set template data
						$objHtmlTemplate->course_name 			= $arrPlainData['course_name'];
						$objHtmlTemplate->token_date 			= $this->parseDate($GLOBALS['TL_CONFIG']['dateFormat'], $arrPlainData['token_date']);
						$objHtmlTemplate->token 				= $arrPlainData['token'];
						$objHtmlTemplate->credits_remaining 	= $arrPlainData['credits_remaining'];
						
						$objHtmlTemplate->child_firstname 		= $arrPlainData['child_firstname'];
						$objHtmlTemplate->child_lastname 		= $arrPlainData['child_lastname'];	
						$objHtmlTemplate->child_email 			= $arrPlainData['child_email'];	
						$objHtmlTemplate->child_phone 			= $arrPlainData['child_phone'];			
						$objHtmlTemplate->child_address = strlen($arrPlainData['child_company']) ? $arrPlainData['child_company'] . '<br />' : '<br />';
						$objHtmlTemplate->child_address .= strlen($arrPlainData['child_street']) ? ucwords(strtolower($arrPlainData['child_street'])) . '<br />' : '<br />';
						$objHtmlTemplate->child_address .= strlen($arrPlainData['child_city']) ? ucwords(strtolower($arrPlainData['child_city'])) . ', ' : '';
						$objHtmlTemplate->child_address .= strlen($arrPlainData['child_state']) ? ucwords(strtolower($GLOBALS['TL_LANG']['DIV'][strtolower($arrCountry[0])][$arrPlainData['child_state']])) . ' ' : '';
						$objHtmlTemplate->child_address .= strlen($arrPlainData['child_postal']) ? ucwords(strtolower($arrPlainData['child_postal'])) . '<br />' : '';
						
						// Create email object
						$objEmail = new Email();
						$objEmail->from = $GLOBALS['TL_CONFIG']['adminEmail'];
						$objEmail->subject = $GLOBALS['TL_LANG']['CB']['MISC']['token_emailsubject'];
						$objEmail->text = $this->parseSimpleTokens($this->replaceInsertTags($objHtmlTemplate->parse()), $arrPlainData);
						
						// Get mail template
						$objEmailTemplate = new IsotopeTemplate('mail_default');
		
						$objEmailTemplate->body = $this->parseSimpleTokens($this->replaceInsertTags($objHtmlTemplate->parse()), $arrPlainData);
						$objEmailTemplate->charset = $GLOBALS['TL_CONFIG']['characterSet'];
						$objEmailTemplate->css = '##head_css##';
		
						// Prevent parseSimpleTokens from stripping important HTML tags
						$GLOBALS['TL_CONFIG']['allowedTags'] .= '<doctype><html><head><meta><style><body>';
						$strHtml = str_replace('<!DOCTYPE', '<DOCTYPE', $objEmailTemplate->parse());
						$strHtml = $this->parseSimpleTokens($this->replaceInsertTags($strHtml), $arrPlainData);
						$strHtml = str_replace('<DOCTYPE', '<!DOCTYPE', $strHtml);
		
						// Parse template
						$objEmail->html = $strHtml;
						$objEmail->imageDir = TL_ROOT . '/';
						
						$objEmail->sendTo($arrDbData['parent_email']);
					}
					catch( Exception $e )
					{
						$this->log('Course Builder email error: ' . $e->getMessage(), __METHOD__, TL_ERROR);
					}
				}
			}
			else
			{
				$this->log('No token email sent: User id: '. $this->User->id .', Course: '. $objCourse->id , __METHOD__, TL_ERROR);
			}
		}
		
	}
	
}