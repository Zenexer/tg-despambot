<?php
declare(strict_types=1);

namespace Zenexer\Telegram\Bot;

use danog\MadelineProto\Logger;

return [
	'sessionFile' => __DIR__ . '/session.madeline',
	'apiSettings' => [
		'pwr' => ['pwr' => false],  // IMPORTANT!
		'logger' => [
			'logger_param' => __DIR__ . '/MadelineProto.log',
			'logger_level' => Logger::ERROR,
		],
	],
	'owner' => '@Zenexer',
	'notifyOwner' => false,
	'badNames' => [
		'╋VX(QQ):253239090【电报群增粉(国内外)(有无username)均可】【群发私聊广告精准直达】【机器人定制】【社群代运营】【twitter,facebook关注、转发】【youtube点赞,评论】【出售成品电报账号】（欢迎社群运营者、项目方、交易所洽谈合作）优质空投分享QQ群473157472 本工作室全网价格最低、服务最好、质量最高 诚招代理',
		'(stockcraft at hotmail.com)We can ADD 1000+ 10000+ or ANY NUMBER REAL and ACTIVE MEMBERS for your TELEGRAM GROUPS-LEAVE NO JOIN ALERTS,QUALITY and QUANTITY GUARANTEED,DEMO AVAILABLE.We also provide READY-MADE TELEGRAM ACCOUNTS and BROADCASTING SERVICE now',
	],
];
