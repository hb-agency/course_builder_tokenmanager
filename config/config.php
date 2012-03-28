<?php if (!defined('TL_ROOT')) die('You can not access this file directly!');

/**
 * TYPOlight webCMS
 * Copyright (C) 2005-2009 Leo Feyer
 *
 * This program is free software: you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation, either
 * version 2.1 of the License, or (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public
 * License along with this program. If not, please visit the Free
 * Software Foundation website at http://www.gnu.org/licenses/.
 *
 * PHP version 5
 * @copyright  Intelligent Spark 2010
 * @author     Fred Bliss <fred.bliss@intelligentspark.com>
 * @license    http://opensource.org/licenses/lgpl-3.0.html
 */

/**
 * Backend modules
 */
array_insert($GLOBALS['BE_MOD']['coursebuilder'], 3, array
(
	'cb_tokens' => array
	(
		'tables'			=> array('tl_iso_tokens', 'tl_member'),
		'stylesheet'		=> 'system/modules/course_builder_tokenmanager/html/backend.css',
		'javsacript'		=> 'system/modules/course_builder_tokenmanager/html/backend.js',
		'icon'				=> 'system/modules/course_builder_tokenmanager/html/icon-token.gif'
	),
));

/**
 * Hooks
 */
$GLOBALS['ISO_HOOKS']['postCheckout'][] 		= array('CBTokenManager', 'postCheckout');
$GLOBALS['ISO_HOOKS']['writeTokens'][] 			= array('CBTokenManager', 'writeTokens');
$GLOBALS['CB_HOOKS']['addMemberClient'][] 		= array('CBTokenManager', 'addMemberClient');
$GLOBALS['CB_HOOKS']['postAddMemberClient'][] 	= array('CBTokenManager', 'setInitialCourseData');
$GLOBALS['TL_HOOKS']['cb_generateCourse'][] 	= array('CBTokenManager', 'startCourse');
$GLOBALS['TL_HOOKS']['cb_getClientResults'][] 	= array('CBTokenManager', 'filterMemberClientData');
$GLOBALS['CB_HOOKS']['insertCourseData'][]		= array('CBTokenManager', 'setCourseDataInsertRow');