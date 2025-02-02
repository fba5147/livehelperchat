<?php

class erLhcoreClassTranslate
{

    /**
     * Fetches bing access token
     */
    public static function getBingAccessToken(& $translationConfig, & $translationData)
    {
        if (! isset($translationData['bing_access_token']) || $translationData['bing_access_token_expire'] < time() + 10) {
            $accessTokenData = erLhcoreClassTranslateBing::getAccessToken($translationData['bing_client_secret'], $translationData['bing_region']);
            $translationData['bing_access_token'] = $accessTokenData['at'];
            $translationData['bing_access_token_expire'] = time() + $accessTokenData['exp'];
            $translationConfig->value = serialize($translationData);
            $translationConfig->saveThis();
        }
    }

    /**
     * Set's chat language
     *
     * Detects chats languages, operator and visitor and translates recent chat messages
     *
     * @param erLhcoreClassModelChat $chat            
     *
     * @param string $visitorLanguage
     *            | Optional
     *            
     * @param string $operatorLanguage
     *            | Optional
     *            
     * @return void || Exception
     *        
     */
    public static function setChatLanguages(erLhcoreClassModelChat $chat, $visitorLanguage, $operatorLanguage, $params = array())
    {
        $originalLanguages = array(
            'chat_locale' => $chat->chat_locale,
            'chat_locale_to' => $chat->chat_locale_to
        );
        
        $supportedLanguages = self::getSupportedLanguages(true);
        $db = ezcDbInstance::get();
        $data = array();
        
        if (key_exists($visitorLanguage, $supportedLanguages)) {
            $chat->chat_locale = $data['chat_locale'] = $visitorLanguage;
        } else {
            // We take few first messages from visitor and try to detect language
            $stmt = $db->prepare('SELECT msg FROM lh_msg WHERE chat_id = :chat_id AND user_id = 0 ORDER BY id ASC LIMIT 3 OFFSET 0');
            $stmt->bindValue(':chat_id', $chat->id);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as & $row) {
                $row = preg_replace('#\[translation\](.*?)\[/translation\]#is', '', $row);
            }
            
            $msgText = substr(implode("\n", $rows), 0, 500);
            $languageCode = self::detectLanguage($msgText);
            $chat->chat_locale = $data['chat_locale'] = $languageCode;
        }
        
        if (key_exists($operatorLanguage, $supportedLanguages)) {
            $chat->chat_locale_to = $data['chat_locale_to'] = $operatorLanguage;
        } else { // We need to detect opetator language, basically we just take back office language and try to find a match
            $languageCode = substr(erLhcoreClassSystem::instance()->Language, 0, 2);
            if (key_exists($languageCode, $supportedLanguages)) {
                $chat->chat_locale_to = $data['chat_locale_to'] = $languageCode;
            } else {
                throw new Exception(erTranslationClassLhTranslation::getInstance()->getTranslation('chat/translation', 'We could not detect operator language'));
            }
        }
        
        if ($chat->chat_locale == $chat->chat_locale_to) {
            throw new Exception(erTranslationClassLhTranslation::getInstance()->getTranslation('chat/translation', 'Detected operator and visitor languages matches, please choose languages manually'));
        }
        
        // Because chat data can be already be changed we modify just required fields
        $stmt = $db->prepare('UPDATE lh_chat SET chat_locale_to = :chat_locale_to, chat_locale =:chat_locale WHERE id = :chat_id');
        $stmt->bindValue(':chat_id', $chat->id, PDO::PARAM_INT);
        $stmt->bindValue(':chat_locale_to', $data['chat_locale_to'], PDO::PARAM_STR);
        $stmt->bindValue(':chat_locale', $data['chat_locale'], PDO::PARAM_STR);
        $stmt->execute();
        
        // We have to translate only if our languages are different
        if ($chat->chat_locale != '' &&  $chat->chat_locale_to != '' && isset($params['translate_old']) && $params['translate_old'] === true) {
            self::translateChatMessages($chat);
        }
        
        return $data;
    }

    /**
     * translations recent chat messages to chat locale
     *
     * @param erLhcoreClassModelChat $chat            
     *
     * @return void || Exception
     *        
     */
    public static function translateChatMessages(erLhcoreClassModelChat $chat)
    {
        
        // Allow callback provide translation config first
        $response = erLhcoreClassChatEventDispatcher::getInstance()->dispatch('translation.get_config', array());
        if ($response !== false && isset($response['status']) && $response['status'] == erLhcoreClassChatEventDispatcher::STOP_WORKFLOW) {
            $translationData = $response['data'];
        } else {
            $translationConfig = erLhcoreClassModelChatConfig::fetch('translation_data');
            $translationData = $translationConfig->data;
        }
        
        if (isset($translationData['translation_handler'])) {

            if($translationData['translation_handler'] == 'bing') {
            
                $response = erLhcoreClassChatEventDispatcher::getInstance()->dispatch('translation.get_bing_token', array(
                    'translation_config' => & $translationConfig,
                    'translation_data' => & $translationData
                ));
                if ($response !== false && isset($response['status']) && $response['status'] == erLhcoreClassChatEventDispatcher::STOP_WORKFLOW) {
                    // Do nothing
                } else {
                    self::getBingAccessToken($translationConfig, $translationData);
                }
                
                // Only last 10 messages are translated
                $msgs = erLhcoreClassModelmsg::getList(array(
                    'filter' => array(
                        'chat_id' => $chat->id
                    ),
                    'limit' => 10,
                    'sort' => 'id DESC'
                ));
                
                foreach ($msgs as $msg) {
                    
                    if ($msg->user_id != - 1) {
                        // Visitor message
                        // Remove old Translation
                        $msg->msg = preg_replace('#\[translation\](.*?)\[/translation\]#is', '', $msg->msg);
                        
                        if ($msg->user_id == 0) {
                            $msgTranslated = erLhcoreClassTranslateBing::translate($translationData['bing_access_token'], $msg->msg, $chat->chat_locale, $chat->chat_locale_to);
                        } else { // Operator message
                            $msgTranslated = erLhcoreClassTranslateBing::translate($translationData['bing_access_token'], $msg->msg, $chat->chat_locale_to, $chat->chat_locale);
                        }
                        
                        // If translation was successfull store it
                        if (! empty($msgTranslated)) {
                            
                            $msgTranslated = str_ireplace(array(
                                '[/ ',
                                'Url = http: //',
                                '[IMG] ',
                                ' [/img]',
                                '[/ url]',
                                '[/ i]',
                                '[Img]'
                            ), array(
                                '[/',
                                'url=http://',
                                '[img]',
                                '[/img]',
                                '[/url]',
                                '[/i]',
                                '[img]'
                            ), $msgTranslated);
                            
                            $msg->msg .= "[translation]{$msgTranslated}[/translation]";
                            $msg->saveThis();
                        }
                    }
                }
            } elseif ($translationData['translation_handler'] == 'google') {
                // Only last 10 messages are translated
                $msgs = erLhcoreClassModelmsg::getList(array(
                    'filter' => array(
                        'chat_id' => $chat->id
                    ),
                    'limit' => 10,
                    'sort' => 'id DESC'
                ));
                
                $length = 0;
                
                foreach ($msgs as $msg) {
                    if ($msg->user_id != - 1) {
                        // Visitor message
                        // Remove old Translation
                        $msg->msg = preg_replace('#\[translation\](.*?)\[/translation\]#is', '', $msg->msg);
                        
                        if ($msg->user_id == 0) {
                            $msgTranslated = erLhcoreClassTranslateGoogle::translate($translationData['google_api_key'], $msg->msg, $chat->chat_locale, $chat->chat_locale_to, (isset($translationData['google_referrer']) ? $translationData['google_referrer'] : ''));
                        } else { // Operator message
                            $msgTranslated = erLhcoreClassTranslateGoogle::translate($translationData['google_api_key'], $msg->msg, $chat->chat_locale_to, $chat->chat_locale, (isset($translationData['google_referrer']) ? $translationData['google_referrer'] : ''));
                        }
                        
                        $length += mb_strlen($msgTranslated);
                        
                        // If translation was successfull store it
                        if (! empty($msgTranslated)) {
                            
                            $msgTranslated = str_ireplace(array(
                                '[/ ',
                                'Url = http: //',
                                '[IMG] ',
                                ' [/img]',
                                '[/ url]',
                                '[/ i]',
                                '[Img]'
                            ), array(
                                '[/',
                                'url=http://',
                                '[img]',
                                '[/img]',
                                '[/url]',
                                '[/i]',
                                '[img]'
                            ), $msgTranslated);
                            
                            $msg->msg .= "[translation]{$msgTranslated}[/translation]";
                            $msg->saveThis();
                        }
                    }
                }
                
                if ($length > 0) {
                    erLhcoreClassChatEventDispatcher::getInstance()->dispatch('translate.messagetranslated', array(
                        'length' => $length,
                        'chat' => & $chat
                    ));
                }
            } elseif ($translationData['translation_handler'] == 'aws') {

                // Only last 10 messages are translated
                $msgs = erLhcoreClassModelmsg::getList(array(
                    'filter' => array(
                        'chat_id' => $chat->id
                    ),
                    'limit' => 10,
                    'sort' => 'id DESC'
                ));

                $length = 0;

                foreach ($msgs as $msg) {
                    if ($msg->user_id != - 1) {
                        // Visitor message
                        // Remove old Translation
                        $msg->msg = preg_replace('#\[translation\](.*?)\[/translation\]#is', '', $msg->msg);

                        if ($msg->user_id == 0) {
                            $msgTranslated = erLhcoreClassTranslateAWS::translate([
                                'aws_region' => $translationData['aws_region'],
                                'aws_access_key' => (isset($translationData['aws_access_key']) ? $translationData['aws_access_key'] : ''),
                                'aws_secret_key' => (isset($translationData['aws_secret_key']) ? $translationData['aws_secret_key'] : ''),
                            ], $msg->msg, $chat->chat_locale, $chat->chat_locale_to);
                        } else { // Operator message
                            $msgTranslated = erLhcoreClassTranslateAWS::translate([
                                'aws_region' => $translationData['aws_region'],
                                'aws_access_key' => (isset($translationData['aws_access_key']) ? $translationData['aws_access_key'] : ''),
                                'aws_secret_key' => (isset($translationData['aws_secret_key']) ? $translationData['aws_secret_key'] : ''),
                            ], $msg->msg, $chat->chat_locale_to, $chat->chat_locale);
                        }

                        $length += mb_strlen($msgTranslated);

                        // If translation was successfull store it
                        if (! empty($msgTranslated)) {

                            $msgTranslated = str_ireplace(array(
                                '[/ ',
                                'Url = http: //',
                                '[IMG] ',
                                ' [/img]',
                                '[/ url]',
                                '[/ i]',
                                '[Img]'
                            ), array(
                                '[/',
                                'url=http://',
                                '[img]',
                                '[/img]',
                                '[/url]',
                                '[/i]',
                                '[img]'
                            ), $msgTranslated);

                            $msg->msg .= "[translation]{$msgTranslated}[/translation]";
                            $msg->saveThis();
                        }
                    }
                }

                if ($length > 0) {
                    erLhcoreClassChatEventDispatcher::getInstance()->dispatch('translate.messagetranslated', array(
                        'length' => $length,
                        'chat' => & $chat
                    ));
                }

            } elseif ($translationData['translation_handler'] == 'yandex') {
                // Only last 10 messages are translated
                $msgs = erLhcoreClassModelmsg::getList(array(
                    'filter' => array(
                        'chat_id' => $chat->id
                    ),
                    'limit' => 10,
                    'sort' => 'id DESC'
                ));
                
                $length = 0;
                
                foreach ($msgs as $msg) {
                    if ($msg->user_id != - 1) {
                        // Visitor message
                        // Remove old Translation
                        $msg->msg = preg_replace('#\[translation\](.*?)\[/translation\]#is', '', $msg->msg);
                        
                        if ($msg->user_id == 0) {
                            $msgTranslated = erLhcoreClassTranslateYandex::translate($translationData['yandex_api_key'], $msg->msg, $chat->chat_locale, $chat->chat_locale_to);
                        } else { // Operator message
                            $msgTranslated = erLhcoreClassTranslateYandex::translate($translationData['yandex_api_key'], $msg->msg, $chat->chat_locale_to, $chat->chat_locale);
                        }
                        
                        $length += mb_strlen($msgTranslated);
                        
                        // If translation was successfull store it
                        if (! empty($msgTranslated)) {
                            
                            $msgTranslated = str_ireplace(array(
                                '[/ ',
                                'Url = http: //',
                                '[IMG] ',
                                ' [/img]',
                                '[/ url]',
                                '[/ i]',
                                '[Img]'
                            ), array(
                                '[/',
                                'url=http://',
                                '[img]',
                                '[/img]',
                                '[/url]',
                                '[/i]',
                                '[img]'
                            ), $msgTranslated);
                            
                            $msg->msg .= "[translation]{$msgTranslated}[/translation]";
                            $msg->saveThis();
                        }
                    }
                }
                
                if ($length > 0) {
                    erLhcoreClassChatEventDispatcher::getInstance()->dispatch('translate.messagetranslated', array(
                        'length' => $length,
                        'chat' => & $chat
                    ));
                }
            }
        }
    }

    /**
     * Translation config helper to avoid constant fetching from database
     */
    public static function getTranslationConfig()
    {
        static $config = null;
        
        if ($config === null) {
            $response = erLhcoreClassChatEventDispatcher::getInstance()->dispatch('translation.get_config', array());
            if ($response !== false && isset($response['status']) && $response['status'] == erLhcoreClassChatEventDispatcher::STOP_WORKFLOW) {
                $config = $response['data'];
            } else {
                $translationConfig = erLhcoreClassModelChatConfig::fetch('translation_data');
                $config = $translationConfig->data;
            }
        }
        
        return $config;
    }

    /**
     * Helper function which returns supported languages by translation provider Bing || Google
     */
    public static function getSupportedLanguages($returnOptions = false)
    {
        $translationData = self::getTranslationConfig();
        $options = array();
        
        if (isset($translationData['translation_handler'])) {

            if ($translationData['translation_handler'] == 'bing') {
            
                $options['ar'] = 'Arabic';
                $options['bg'] = 'Bulgarian';
                $options['ca'] = 'Catalan';
                $options['zh-CHS'] = 'Chinese Simplified';
                $options['zh-CHT'] = 'Chinese Traditional';
                $options['cs'] = 'Czech';
                $options['da'] = 'Danish';
                $options['nl'] = 'Dutch';
                $options['en'] = 'English';
                $options['et'] = 'Estonian';
                $options['fi'] = 'Finnish';
                $options['fr'] = 'French';
                $options['de'] = 'German';
                $options['ht'] = 'Haitian Creole';
                $options['he'] = 'Hebrew';
                $options['hi'] = 'Hindi';
                $options['mww'] = 'Hmong Daw';
                $options['hu'] = 'Hungarian';
                $options['id'] = 'Indonesian';
                $options['it'] = 'Italian';
                $options['ja'] = 'Japanese';
                $options['tlh'] = 'Klingon';
                $options['tlh-Qaak'] = 'Klingon (pIqaD)';
                $options['ko'] = 'Korean';
                $options['lv'] = 'Latvian';
                $options['lt'] = 'Lithuanian';
                $options['ms'] = 'Malay';
                $options['mt'] = 'Maltese';
                $options['no'] = 'Norwegian';
                $options['fa'] = 'Persian';
                $options['pl'] = 'Polish';
                $options['pt'] = 'Portuguese';
                $options['ro'] = 'Romanian';
                $options['ru'] = 'Russian';
                $options['sk'] = 'Slovak';
                $options['sl'] = 'Slovenian';
                $options['es'] = 'Spanish';
                $options['sv'] = 'Swedish';
                $options['th'] = 'Thai';
                $options['tr'] = 'Turkish';
                $options['uk'] = 'Ukrainian';
                $options['ur'] = 'Urdu';
                $options['vi'] = 'Vietnamese';
                $options['cy'] = 'Urdu';
                $options['yi'] = 'Welsh';

            } elseif ($translationData['translation_handler'] == 'aws') {

                    $options['af'] = 'Afrikaans';
                	$options['sq'] = 'Albanian';
                	$options['am'] ='Amharic';
                	$options['ar'] ='Arabic';
                	$options['hy'] ='Armenian';
                	$options['az'] ='Azerbaijani';
                	$options['bn'] ='Bengali';
                	$options['bs'] ='Bosnian';
                	$options['bg'] ='Bulgarian';
                	$options['ca'] ='Catalan';
                	$options['zh'] ='Chinese (Simplified)';
                 	$options['zh-TW'] = 'Chinese (Traditional)';
                	$options['hr'] ='Croatian';
                	$options['cs'] ='Czech';
                	$options['da'] ='Danish';
                	$options['fa-AF'] ='Dari';
                	$options['nl'] ='Dutch';
                	$options['en'] ='English';
                	$options['et'] ='Estonian';
                	$options['fa'] ='Farsi (Persian)';
                	$options['tl'] ='Filipino, Tagalog';
                	$options['fi'] ='Finnish';
                	$options['fr'] ='French';
                	$options['fr-CA'] ='French (Canada)';
                	$options['ka'] ='Georgian';
                	$options['de'] ='German';
                	$options['el'] ='Greek';
                	$options['gu'] ='Gujarati';
               	    $options['ht'] = 'Haitian Creole';
                	$options['ha'] ='Hausa';
                	$options['he'] ='Hebrew';
                	$options['hi'] ='Hindi';
                	$options['hu'] ='Hungarian';
                	$options['is'] ='Icelandic';
                	$options['id'] ='Indonesian';
                	$options['it'] ='Italian';
                	$options['ja'] ='Japanese';
                	$options['kn'] ='Kannada';
                	$options['kk'] ='Kazakh';
                	$options['ko'] ='Korean';
                	$options['lv'] ='Latvian';
                	$options['lt'] ='Lithuanian';
                	$options['mk'] ='Macedonian';
                	$options['ms'] = 'Malay';
                	$options['ml'] ='Malayalam';
                	$options['mt'] ='Maltese';
                	$options['mn'] ='Mongolian';
                	$options['no'] ='Norwegian';
                	$options['ps'] ='Pashto';
                	$options['pl'] ='Polish';
                	$options['pt'] ='Portuguese';
                	$options['ro'] ='Romanian';
                	$options['ru'] ='Russian';
                	$options['sr'] ='Serbian';
                	$options['si'] ='Sinhala';
                	$options['sk'] ='Slovak';
                	$options['sl'] ='Slovenian';
                	$options['so'] ='Somali';
                	$options['es'] ='Spanish';
                	$options['es-MX'] = 'Spanish (Mexico)';
                	$options['sw'] ='Swahili';
                	$options['sv'] ='Swedish';
                	$options['ta'] ='Tamil';
                	$options['te'] ='Telugu';
                	$options['th'] ='Thai';
                	$options['tr'] ='Turkish';
                	$options['uk'] ='Ukrainian';
                	$options['ur'] ='Urdu';
                	$options['uz'] ='Uzbek';
                	$options['vi'] ='Vietnamese';
                	$options['cy'] ='Welsh';

            } elseif ($translationData['translation_handler'] == 'google') {

                $options['af'] = 'Afrikaans';
                $options['sq'] = 'Albanian';
                $options['ar'] = 'Arabic';
                $options['az'] = 'Azerbaijani';
                $options['eu'] = 'Basque';
                $options['bn'] = 'Bengali';
                $options['be'] = 'Belarusian';
                $options['bg'] = 'Bulgarian';
                $options['ca'] = 'Catalan';
                $options['zh-CN'] = 'Chinese Simplified';
                $options['zh-TW'] = 'Chinese Traditional';
                $options['hr'] = 'Croatian';
                $options['cs'] = 'Czech';
                $options['da'] = 'Danish';
                $options['nl'] = 'Dutch';
                $options['en'] = 'English';
                $options['eo'] = 'Esperanto';
                $options['et'] = 'Estonian';
                $options['tl'] = 'Filipino';
                $options['fi'] = 'Finnish';
                $options['fr'] = 'French';
                $options['gl'] = 'Galician';
                $options['ka'] = 'Georgian';
                $options['de'] = 'German';
                $options['el'] = 'Greek';
                $options['gu'] = 'Gujarati';
                $options['ht'] = 'Haitian Creole';
                $options['iw'] = 'Hebrew';
                $options['hi'] = 'Hindi';
                $options['hu'] = 'Hungarian';
                $options['is'] = 'Icelandic';
                $options['id'] = 'Indonesian';
                $options['ga'] = 'Irish';
                $options['it'] = 'Italian';
                $options['ja'] = 'Japanese';
                $options['kn'] = 'Kannada';
                $options['ko'] = 'Korean';
                $options['la'] = 'Latin';
                $options['lv'] = 'Latvian';
                $options['lt'] = 'Lithuanian';
                $options['mk'] = 'Macedonian';
                $options['ms'] = 'Malay';
                $options['mt'] = 'Maltese';
                $options['no'] = 'Norwegian';
                $options['fa'] = 'Persian';
                $options['pl'] = 'Polish';
                $options['pt'] = 'Portuguese';
                $options['ro'] = 'Romanian';
                $options['ru'] = 'Russian';
                $options['sr'] = 'Serbian';
                $options['sk'] = 'Slovak';
                $options['sl'] = 'Slovenian';
                $options['es'] = 'Spanish';
                $options['sw'] = 'Swahili';
                $options['sv'] = 'Swedish';
                $options['ta'] = 'Tamil';
                $options['te'] = 'Telugu';
                $options['th'] = 'Thai';
                $options['tr'] = 'Turkish';
                $options['uk'] = 'Ukrainian';
                $options['ur'] = 'Urdu';
                $options['vi'] = 'Vietnamese';
                $options['cy'] = 'Urdu';
                $options['yi'] = 'Welsh';

            } elseif ($translationData['translation_handler'] == 'yandex') {

                $options['az'] = 'Azerbaijan';
                $options['sq'] = 'Albanian';
                $options['am'] = 'Amharic';
                $options['en'] = 'English';
                $options['ar'] = 'Arabic';
                $options['hy'] = 'Armenian';
                $options['af'] = 'Afrikaans';
                $options['eu'] = 'Basque';
                $options['ba'] = 'Bashkir';
                $options['be'] = 'Belarusian';
                $options['bn'] = 'Bengali';
                $options['my'] = 'Burmese';
                $options['bg'] = 'Bulgarian';
                $options['bs'] = 'Bosnian';
                $options['cy'] = 'Welsh';
                $options['hu'] = 'Hungarian';
                $options['vi'] = 'Vietnamese';
                $options['ht'] = 'Haitian (Creole)';
                $options['gl'] = 'Galician';
                $options['nl'] = 'Dutch';
                $options['mrj'] = 'Hill Mari';
                $options['el'] = 'Greek';
                $options['ka'] = 'Georgian';
                $options['gu'] = 'Gujarati';
                $options['da'] = 'Danish';
                $options['he'] = 'Hebrew';
                $options['yi'] = 'Yiddish';
                $options['id'] = 'Indonesian';
                $options['ga'] = 'Irish';
                $options['it'] = 'Italian';
                $options['is'] = 'Icelandic';
                $options['es'] = 'Spanish';
                $options['kk'] = 'Kazakh';
                $options['kn'] = 'Kannada';
                $options['ca'] = 'Catalan';
                $options['ky'] = 'Kyrgyz';
                $options['zh'] = 'Chinese';
                $options['ko'] = 'Korean';
                $options['xh'] = 'Xhosa';
                $options['km'] = 'Khmer';
                $options['lo'] = 'Laotian';
                $options['la'] = 'Latin';
                $options['lv'] = 'Latvian';
                $options['lt'] = 'Lithuanian';
                $options['lb'] = 'Luxembourgish';
                $options['mg'] = 'Malagasy';
                $options['ms'] = 'Malay';
                $options['ml'] = 'Malayalam';
                $options['mt'] = 'Maltese';
                $options['mk'] = 'Macedonian';
                $options['mi'] = 'Maori';
                $options['mr'] = 'Marathi';
                $options['mhr'] = 'Mari';
                $options['mn'] = 'Mongolian';
                $options['de'] = 'German';
                $options['ne'] = 'Nepali';
                $options['no'] = 'Norwegian';
                $options['pa'] = 'Punjabi';
                $options['pap'] = 'Papiamento';
                $options['fa'] = 'Persian';
                $options['pl'] = 'Polish';
                $options['pt'] = 'Portuguese';
                $options['ro'] = 'Romanian';
                $options['ru'] = 'Russian';
                $options['ceb'] = 'Cebuano';
                $options['sr'] = 'Serbian';
                $options['si'] = 'Sinhala';
                $options['sk'] = 'Slovakian';
                $options['sl'] = 'Slovenian';
                $options['sw'] = 'Swahili';
                $options['su'] = 'Sundanese';
                $options['tg'] = 'Tajik';
                $options['th'] = 'Thai';
                $options['tl'] = 'Tagalog';
                $options['ta'] = 'Tamil';
                $options['tt'] = 'Tatar';
                $options['te'] = 'Telugu';
                $options['tr'] = 'Turkish';
                $options['udm'] = 'Udmurt';
                $options['uz'] = 'Uzbek';
                $options['uk'] = 'Ukrainian';
                $options['ur'] = 'Urdu';
                $options['fi'] = 'Finnish';
                $options['fr'] = 'French';
                $options['hi'] = 'Hindi';
                $options['hr'] = 'Croatian';
                $options['cs'] = 'Czech';
                $options['sv'] = 'Swedish';
                $options['gd'] = 'Scottish';
                $options['et'] = 'Estonian';
                $options['eo'] = 'Esperanto';
                $options['jv'] = 'Javanese';
                $options['ja'] = 'Japanese';
            }
        }
        
        if ($returnOptions == true) {
            return $options;
        }
        
        $optionsObjects = array();
        foreach ($options as $key => $option) {
            $std = new stdClass();
            $std->id = $key;
            $std->name = $option;
            $optionsObjects[] = $std;
        }
        
        return $optionsObjects;
    }

    /**
     * Detects language by text
     *
     * @param
     *            string text
     *            
     * @return language code
     *        
     *        
     */
    public static function detectLanguage($text)
    {
        // If batch translation extract very first text
        if (is_array($text)) {
            $text = $text[0]['source'];
        }

        $response = erLhcoreClassChatEventDispatcher::getInstance()->dispatch('translation.get_config', array());
        if ($response !== false && isset($response['status']) && $response['status'] == erLhcoreClassChatEventDispatcher::STOP_WORKFLOW) {
            $translationData = $response['data'];
        } else {
            $translationConfig = erLhcoreClassModelChatConfig::fetch('translation_data');
            $translationData = $translationConfig->data;
        }
        
        if (isset($translationData['translation_handler'])) {
            if($translationData['translation_handler'] == 'bing') {
            
                $response = erLhcoreClassChatEventDispatcher::getInstance()->dispatch('translation.get_bing_token', array(
                    'translation_config' => & $translationConfig,
                    'translation_data' => & $translationData
                ));
                if ($response !== false && isset($response['status']) && $response['status'] == erLhcoreClassChatEventDispatcher::STOP_WORKFLOW) {
                    // Do nothing
                } else {
                    self::getBingAccessToken($translationConfig, $translationData);
                }
                
                return erLhcoreClassTranslateBing::detectLanguage($translationData['bing_access_token'], $text);
            } elseif ($translationData['translation_handler'] == 'google') {
                return erLhcoreClassTranslateGoogle::detectLanguage($translationData['google_api_key'], $text, (isset($translationData['google_referrer']) ? $translationData['google_referrer'] : ''));
            } elseif($translationData['translation_handler'] == 'yandex') {
                return erLhcoreClassTranslateYandex::detectLanguage($translationData['yandex_api_key'], $text);
            } elseif($translationData['translation_handler'] == 'aws') {
               return erLhcoreClassTranslateAWS::detectLanguage([
                    'aws_region' => $translationData['aws_region'],
                    'aws_access_key' => (isset($translationData['aws_access_key']) ? $translationData['aws_access_key'] : ''),
                    'aws_secret_key' => (isset($translationData['aws_secret_key']) ? $translationData['aws_secret_key'] : ''),
                ], $text);
            }
        }
    }

    /**
     * Translations provided text from source to destination language
     *
     * @param string $text            
     *
     * @param string $translateFrom            
     *
     * @param string $translateTo            
     *
     *
     */
    public static function translateTo($text, $translateFrom, $translateTo)
    {
        $response = erLhcoreClassChatEventDispatcher::getInstance()->dispatch('translation.get_config', array());
        if ($response !== false && isset($response['status']) && $response['status'] == erLhcoreClassChatEventDispatcher::STOP_WORKFLOW) {
            $translationData = $response['data'];
        } else {
            $translationConfig = erLhcoreClassModelChatConfig::fetch('translation_data');
            $translationData = $translationConfig->data;
        }

        if (isset($translationData['translation_handler'])) {

            $key = erLhcoreClassModelChat::multi_implode(',', $text) . $translateFrom . '_' . $translateTo;
            $useCache = isset($translationData['use_cache']) && $translationData['use_cache'] == true;

            if ($useCache && ($responseCache = erLhcoreClassModelGenericBotRestAPICache::findOne(['sort' => false, 'filter' => ['hash' => md5($key), 'rest_api_id' => 0]])) && $responseCache instanceof erLhcoreClassModelGenericBotRestAPICache) {
                return json_decode($responseCache->response,true);
            }

            $translatedItem = null;

            if ($translationData['translation_handler'] == 'bing') {
            
                $response = erLhcoreClassChatEventDispatcher::getInstance()->dispatch('translation.get_bing_token', array(
                    'translation_config' => & $translationConfig,
                    'translation_data' => & $translationData
                ));
                if ($response !== false && isset($response['status']) && $response['status'] == erLhcoreClassChatEventDispatcher::STOP_WORKFLOW) {
                    // Do nothing
                } else {
                    self::getBingAccessToken($translationConfig, $translationData);
                }

                $supportedLanguages = self::getSupportedLanguages(true);

                if ($translateFrom == false) {
                    $translateFrom = self::detectLanguage($text);
                } else {
                    if (!key_exists($translateFrom, $supportedLanguages)) {
                        throw new Exception(erTranslationClassLhTranslation::getInstance()->getTranslation('chat/translation', 'Operator language is not supported by Google translation service'). ' [' . $translateFrom . ']' );
                    }
                }

                $translatedItem = erLhcoreClassTranslateBing::translate($translationData['bing_access_token'], $text, $translateFrom, $translateTo);
                
            } elseif ($translationData['translation_handler'] == 'google') {

                $supportedLanguages = self::getSupportedLanguages(true);

                if ($translateFrom === false) {
                    $translateFrom = self::detectLanguage($text, (isset($translationData['google_referrer']) ? $translationData['google_referrer'] : ''));
                } else {
                    if (!key_exists($translateFrom, $supportedLanguages)) {
                        throw new Exception(erTranslationClassLhTranslation::getInstance()->getTranslation('chat/translation', 'Operator language is not supported by Google translation service'). ' [' . $translateFrom . ']' );
                    }
                }

                if (!key_exists($translateTo, $supportedLanguages)) {
                    throw new Exception(erTranslationClassLhTranslation::getInstance()->getTranslation('chat/translation', 'Visitor language is not supported by Google translation service!'). ' [' . $translateTo . ']' );
                }

                $translatedItem = erLhcoreClassTranslateGoogle::translate($translationData['google_api_key'], $text, $translateFrom, $translateTo, (isset($translationData['google_referrer']) ? $translationData['google_referrer'] : ''));
            } elseif ($translationData['translation_handler'] == 'aws') {

                if ($translateFrom == false) {
                    $translateFrom = 'auto';
                } else {
                    $supportedLanguages = self::getSupportedLanguages(true);
                    if (!key_exists($translateFrom, $supportedLanguages)) {
                        // If not supported language fallback to auto
                        $translateFrom = 'auto';
                    }
                }

                $translatedItem =  erLhcoreClassTranslateAWS::translate([
                    'aws_region' => $translationData['aws_region'],
                    'aws_access_key' => (isset($translationData['aws_access_key']) ? $translationData['aws_access_key'] : ''),
                    'aws_secret_key' => (isset($translationData['aws_secret_key']) ? $translationData['aws_secret_key'] : ''),
                ], $text, $translateFrom, $translateTo);

            } elseif ($translationData['translation_handler'] == 'yandex') {

                $supportedLanguages = self::getSupportedLanguages(true);

                if ($translateFrom == false) {
                    $translateFrom = self::detectLanguage($text);
                } else {
                    if (!key_exists($translateFrom, $supportedLanguages)) {
                        throw new Exception(erTranslationClassLhTranslation::getInstance()->getTranslation('chat/translation', 'Operator language is not supported by Google translation service'). ' [' . $translateFrom . ']' );
                    }
                }

                $translatedItem =  erLhcoreClassTranslateYandex::translate($translationData['yandex_api_key'], $text, $translateFrom, $translateTo);
            }

            if ($useCache) {
                $translationCache = new erLhcoreClassModelGenericBotRestAPICache();
                $translationCache->hash = md5($key);
                $translationCache->response = json_encode($translatedItem);
                $translationCache->saveThis();
            }

            return $translatedItem;
        }
    }

    /**
     * We translation operator language to visitor language
     *
     * @param erLhcoreClassModelChat $chat            
     *
     * @param erLhcoreClassModelmsg $msg            
     *
     *
     */
    public static function translateChatMsgOperator(erLhcoreClassModelChat $chat, erLhcoreClassModelmsg & $msg)
    {
        try {
            
            // Remove old Translation
            $msg->msg = preg_replace('#\[translation\](.*?)\[/translation\]#is', '', $msg->msg);
            
            $translation = self::translateTo($msg->msg, $chat->chat_locale_to, $chat->chat_locale);
            
            // If translation was successfull store it
            if (! empty($translation)) {
                
                $translation = str_ireplace(array(
                    '[/ ',
                    'Url = http: //',
                    '[IMG] ',
                    ' [/img]',
                    '[/ url]',
                    '[/ i]',
                    '[Img]'
                ), array(
                    '[/',
                    'url=http://',
                    '[img]',
                    '[/img]',
                    '[/url]',
                    '[/i]',
                    '[img]'
                ), $translation);
                
                $msg->msg .= "[translation]{$translation}[/translation]";
            }
        } catch (Exception $e) {}
    }

    /**
     * We translation visitor language to operator language
     *
     * @param erLhcoreClassModelChat $chat            
     *
     * @param
     *            erLhcoreClassModelmsg & $msg
     *            
     *            
     */
    public static function translateChatMsgVisitor(erLhcoreClassModelChat $chat, erLhcoreClassModelmsg & $msg)
    {
        try {
            
            // Remove old Translation
            $msg->msg = preg_replace('#\[translation\](.*?)\[/translation\]#is', '', $msg->msg);
            
            $translation = self::translateTo($msg->msg, $chat->chat_locale, $chat->chat_locale_to);
            
            // If translation was successfull store it
            if (! empty($translation)) {
                
                $translation = str_ireplace(array(
                    '[/ ',
                    'Url = http: //',
                    '[IMG] ',
                    ' [/img]',
                    '[/ url]',
                    '[/ i]',
                    '[Img]'
                ), array(
                    '[/',
                    'url=http://',
                    '[img]',
                    '[/img]',
                    '[/url]',
                    '[/i]',
                    '[img]'
                ), $translation);
                
                $msg->msg .= "[translation]{$translation}[/translation]";
            }
        } catch (Exception $e) {}
    }
    
    public static function translateBotMessage(erLhcoreClassModelChat $chat, erLhcoreClassModelmsg & $msg, erLhcoreClassModelGenericBotTrGroup $translationGroup)
    {
        $supportedLanguages = self::getSupportedLanguages(true);

        // Unsupported
        if (!key_exists($translationGroup->bot_lang, $supportedLanguages)) {
            return;
        }

        $chatLocale = explode('-',$chat->chat_locale)[0];

        // Unsupported
        if (!key_exists($chatLocale, $supportedLanguages)) {
            return;
        }

        try {

            if ($msg->meta_msg != '') {
                self::collectPathsToTranslate($msg, $translationGroup->bot_lang, $chatLocale);
            }

            if (!empty($msg->msg)) {
                $msg->msg = self::translateTo($msg->msg, $translationGroup->bot_lang, $chatLocale);
            }

        } catch (Exception $e) {
            erLhcoreClassLog::write( $e->getMessage() . "\n" . $e->getTraceAsString(),
                ezcLog::SUCCESS_AUDIT,
                array(
                    'source' => 'lhc',
                    'category' => 'translation',
                    'line' => $e->getLine(),
                    'file' => $e->getFile(),
                    'object_id' => (int)$chat->id
                )
            );
        }
    }

    public static function arrayPath(&$array, $path = array(), &$value = null)
    {
        $args = func_get_args();
        $ref = &$array;
        foreach ($path as $key) {
            if (!is_array($ref)) {
                $ref = array();
            }
            $ref = &$ref[$key];
        }
        $prev = $ref;
        if (array_key_exists(2, $args)) {
            // value param was passed -> we're setting
            $ref = $value;  // set the value
        }
        return $prev;
    }

    public static function collectPathsToTranslate($msg, $fromLanguage, $toLanguage)
    {
        $elements = json_decode($msg->meta_msg, true);

        if ($elements === null) {
            return;
        }

        $pathsTranslate = [];

        if (isset($elements['content']['quick_replies']) && is_array($elements['content']['quick_replies']) &&!empty($elements['content']['quick_replies'])) {
            foreach ($elements['content']['quick_replies'] as $index => $item) {
                if (isset($item['content']['name']) && !empty($item['content']['name'])) {
                    $pathsTranslate[] = [
                        'path' => "content.quick_replies.{$index}.content.name",
                        'source' => $item['content']['name'],
                        'target' => $item['content']['name']
                    ];
                }
            }
        }

        if (isset($elements['content']['buttons_generic']) && is_array($elements['content']['buttons_generic']) &&!empty($elements['content']['buttons_generic'])) {
            foreach ($elements['content']['buttons_generic'] as $index => $item) {
                if (isset($item['content']['name']) && !empty($item['content']['name'])) {
                    $pathsTranslate[] = [
                        'path' => "content.buttons_generic.{$index}.content.name",
                        'source' => $item['content']['name'],
                        'target' => $item['content']['name']
                    ];
                }
            }
        }

        if (isset($elements['content']['list']['items']) && is_array($elements['content']['list']['items']) &&!empty($elements['content']['list']['items'])) {
            foreach ($elements['content']['list']['items'] as $index => $item) {
                if (isset($item['content']['title']) && !empty($item['content']['title'])) {
                    $pathsTranslate[] = [
                        'path' => "content.list.items.{$index}.content.title",
                        'source' => $item['content']['title'],
                        'target' => $item['content']['title']
                    ];
                }
                if (isset($item['content']['subtitle']) && !empty($item['content']['subtitle'])) {
                    $pathsTranslate[] = [
                        'path' => "content.list.items.{$index}.content.subtitle",
                        'source' => $item['content']['subtitle'],
                        'target' => $item['content']['subtitle']
                    ];
                }
            }
        }

        if (isset($elements['content']['list']['list_quick_replies']) && is_array($elements['content']['list']['list_quick_replies']) &&!empty($elements['content']['list']['list_quick_replies'])) {
            foreach ($elements['content']['list']['list_quick_replies'] as $index => $item) {
                if (isset($item['content']['name']) && !empty($item['content']['name'])) {
                    $pathsTranslate[] = [
                        'path' => "content.list.list_quick_replies.{$index}.content.name",
                        'source' => $item['content']['name'],
                        'target' => $item['content']['name']
                    ];
                }
            }
        }

        if (isset($elements['content']['generic']['items']) && is_array($elements['content']['generic']['items']) &&!empty($elements['content']['generic']['items'])) {
            foreach ($elements['content']['generic']['items'] as $index => $item) {

                if (isset($item['content']['title']) && !empty($item['content']['title'])) {
                    $pathsTranslate[] = [
                        'path' => "content.generic.items.{$index}.content.title",
                        'source' => $item['content']['title'],
                        'target' => $item['content']['title']
                    ];
                }

                if (isset($item['content']['subtitle']) && !empty($item['content']['subtitle'])) {
                    $pathsTranslate[] = [
                        'path' => "content.generic.items.{$index}.content.subtitle",
                        'source' => $item['content']['subtitle'],
                        'target' => $item['content']['subtitle']
                    ];
                }

                if (isset($item['buttons']) && !empty($item['buttons'])) {
                    foreach ($item['buttons'] as $indexSub => $button) {
                        if (isset($button['content']['name']) && !empty($button['content']['name'])) {
                            $pathsTranslate[] = [
                                'path' => "content.generic.items.{$index}.buttons.{$indexSub}.content.name",
                                'source' => $button['content']['name'],
                                'target' => $button['content']['name']
                            ];
                        }
                    }
                }
            }
        }

        if (isset($elements['content']['typing']['text']) && $elements['content']['typing']['text'] != '') {
            $pathsTranslate[] = [
                'path' => "content.typing.text",
                'source' => $elements['content']['typing']['text'],
                'target' => $elements['content']['typing']['text']
            ];
        }

        if (empty($pathsTranslate)) {
            return;
        }

        $translatedItems = self::translateTo($pathsTranslate, $fromLanguage, $toLanguage);

        foreach ($translatedItems as $translatedItem) {
            self::arrayPath($elements, explode('.',$translatedItem['path']), $translatedItem['target']);
        }

        $msg->meta_msg = json_encode($elements);
    }
}

?>
