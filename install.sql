SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";

CREATE TABLE IF NOT EXISTS `ww_sessions` (
  `id` varchar(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `lifetime` int(8) NOT NULL,
  `user` int(10) NOT NULL,
  `data` text NOT NULL,
  `expire` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user` (`user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `ww_usergroups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `accesslevel` tinyint(2) NOT NULL,
  `permissions` varchar(200) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=4;

INSERT INTO `ww_usergroups` (`id`, `name`, `accesslevel`, `permissions`) VALUES
(1, 'Guest', 0, ''),
(2, 'User', 1, ''),
(3, 'Administrator', 2, '');

CREATE TABLE IF NOT EXISTS `ww_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(30) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `password` varchar(40) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `email` varchar(40) NOT NULL,
  `group` int(10) unsigned NOT NULL,
  `lastactive` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=2;

INSERT INTO `ww_users` (`id`, `username`, `password`, `email`, `group`, `lastactive`) VALUES
(1, 'admin', 'd033e22ae348aeb5660fc2140aec35850c4da997', 'example@example.com', 3, '0000-00-00 00:00:00');

CREATE TABLE IF NOT EXISTS `ww_languages` (
  `id` varchar(5) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `name` varchar(50) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `direction` varchar(3) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `locales` varchar(50) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT INTO `ww_languages` (`id`, `name`, `direction`, `locales`) VALUES
('en', 'English', 'ltr', 'en_US.UTF-8,en_US,eng,English');

CREATE TABLE IF NOT EXISTS `ww_translations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `language` varchar(5) NOT NULL,
  `string` text CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `translation` text CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `pack` (`language`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=2;
