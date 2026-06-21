-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Erstellungszeit: 21. Jun 2026 um 23:14
-- Server-Version: 10.6.23-MariaDB-0ubuntu0.22.04.1-log
-- PHP-Version: 7.4.33-nmm8

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Datenbank: `d04768bb`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `einstellungen`
--

CREATE TABLE `einstellungen` (
  `id` int(10) UNSIGNED NOT NULL,
  `saal_id` int(10) UNSIGNED NOT NULL,
  `nc_api_key` varchar(255) DEFAULT NULL,
  `song_api_url` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `modul_instanzen`
--

CREATE TABLE `modul_instanzen` (
  `id` int(10) UNSIGNED NOT NULL,
  `modul_typ` varchar(50) NOT NULL,
  `name` varchar(150) NOT NULL,
  `einstellungen` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`einstellungen`)),
  `erstellt_am` datetime NOT NULL DEFAULT current_timestamp(),
  `geaendert_am` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Daten für Tabelle `modul_instanzen`
--

INSERT INTO `modul_instanzen` (`id`, `modul_typ`, `name`, `einstellungen`, `erstellt_am`, `geaendert_am`) VALUES
(14, 'bild', 'Test Bilder', '{\"intervall_sek\":5,\"uebergang\":\"fade\",\"bildmodus\":\"cover\"}', '2026-06-20 10:53:04', '2026-06-20 10:53:04');

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `modul_instanz_inhalte`
--

CREATE TABLE `modul_instanz_inhalte` (
  `id` int(10) UNSIGNED NOT NULL,
  `modul_instanz_id` int(10) UNSIGNED NOT NULL,
  `dateiname` varchar(255) DEFAULT NULL,
  `text_inhalt` text DEFAULT NULL,
  `ablaufdatum` date DEFAULT NULL,
  `reihenfolge` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `dauer_sek` int(10) UNSIGNED NOT NULL DEFAULT 10
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Daten für Tabelle `modul_instanz_inhalte`
--

INSERT INTO `modul_instanz_inhalte` (`id`, `modul_instanz_id`, `dateiname`, `text_inhalt`, `ablaufdatum`, `reihenfolge`, `dauer_sek`) VALUES
(29, 14, 'bild_14_4449c915b68a.jpg', NULL, NULL, 0, 10),
(30, 14, 'bild_14_97bc060f302e.png', NULL, NULL, 1, 10),
(31, 14, 'bild_14_a2f9fe6ae8d5.png', NULL, NULL, 2, 10);

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `playlists`
--

CREATE TABLE `playlists` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `aktiv` tinyint(1) NOT NULL DEFAULT 1,
  `erstellt_am` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `playlist_layout`
--

CREATE TABLE `playlist_layout` (
  `id` int(10) UNSIGNED NOT NULL,
  `playlist_id` int(10) UNSIGNED NOT NULL,
  `spalten_anzahl` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `spalte1_breite` tinyint(3) UNSIGNED DEFAULT NULL,
  `spalte2_breite` tinyint(3) UNSIGNED DEFAULT NULL,
  `spalte3_breite` tinyint(3) UNSIGNED DEFAULT NULL,
  `header_uhrzeit` tinyint(1) NOT NULL DEFAULT 1,
  `footer_ticker` tinyint(1) NOT NULL DEFAULT 1
) ;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `playlist_saele`
--

CREATE TABLE `playlist_saele` (
  `playlist_id` int(10) UNSIGNED NOT NULL,
  `saal_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `playlist_spalten_inhalte`
--

CREATE TABLE `playlist_spalten_inhalte` (
  `id` int(10) UNSIGNED NOT NULL,
  `playlist_id` int(10) UNSIGNED NOT NULL,
  `spalte` tinyint(3) UNSIGNED NOT NULL,
  `reihenfolge` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `modul_instanz_id` int(10) UNSIGNED NOT NULL,
  `layout_override` varchar(100) DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `playlist_zeitregeln`
--

CREATE TABLE `playlist_zeitregeln` (
  `id` int(10) UNSIGNED NOT NULL,
  `playlist_id` int(10) UNSIGNED NOT NULL,
  `wochentage` varchar(20) NOT NULL,
  `von_uhrzeit` time NOT NULL,
  `bis_uhrzeit` time NOT NULL,
  `prioritaet` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `saele`
--

CREATE TABLE `saele` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `subdomain` varchar(100) NOT NULL,
  `erstellt_am` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `ticker_eintraege`
--

CREATE TABLE `ticker_eintraege` (
  `id` int(10) UNSIGNED NOT NULL,
  `ticker_playlist_id` int(10) UNSIGNED NOT NULL,
  `text` text NOT NULL,
  `reihenfolge` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `dauer_sek` int(10) UNSIGNED NOT NULL DEFAULT 8
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `ticker_playlists`
--

CREATE TABLE `ticker_playlists` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `aktiv` tinyint(1) NOT NULL DEFAULT 1,
  `erstellt_am` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `ticker_playlist_saele`
--

CREATE TABLE `ticker_playlist_saele` (
  `ticker_playlist_id` int(10) UNSIGNED NOT NULL,
  `saal_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `ticker_zeitregeln`
--

CREATE TABLE `ticker_zeitregeln` (
  `id` int(10) UNSIGNED NOT NULL,
  `ticker_playlist_id` int(10) UNSIGNED NOT NULL,
  `wochentage` varchar(20) NOT NULL,
  `von_uhrzeit` time NOT NULL,
  `bis_uhrzeit` time NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indizes der exportierten Tabellen
--

--
-- Indizes für die Tabelle `einstellungen`
--
ALTER TABLE `einstellungen`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_saal` (`saal_id`);

--
-- Indizes für die Tabelle `modul_instanzen`
--
ALTER TABLE `modul_instanzen`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_modul_typ` (`modul_typ`);

--
-- Indizes für die Tabelle `modul_instanz_inhalte`
--
ALTER TABLE `modul_instanz_inhalte`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_modul_instanz` (`modul_instanz_id`);

--
-- Indizes für die Tabelle `playlists`
--
ALTER TABLE `playlists`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `playlist_layout`
--
ALTER TABLE `playlist_layout`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_playlist` (`playlist_id`);

--
-- Indizes für die Tabelle `playlist_saele`
--
ALTER TABLE `playlist_saele`
  ADD PRIMARY KEY (`playlist_id`,`saal_id`),
  ADD KEY `fk_playlist_saele_saal` (`saal_id`);

--
-- Indizes für die Tabelle `playlist_spalten_inhalte`
--
ALTER TABLE `playlist_spalten_inhalte`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_playlist_spalte` (`playlist_id`,`spalte`),
  ADD KEY `idx_modul_instanz` (`modul_instanz_id`);

--
-- Indizes für die Tabelle `playlist_zeitregeln`
--
ALTER TABLE `playlist_zeitregeln`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_playlist` (`playlist_id`);

--
-- Indizes für die Tabelle `saele`
--
ALTER TABLE `saele`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_subdomain` (`subdomain`);

--
-- Indizes für die Tabelle `ticker_eintraege`
--
ALTER TABLE `ticker_eintraege`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ticker_playlist` (`ticker_playlist_id`);

--
-- Indizes für die Tabelle `ticker_playlists`
--
ALTER TABLE `ticker_playlists`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `ticker_playlist_saele`
--
ALTER TABLE `ticker_playlist_saele`
  ADD PRIMARY KEY (`ticker_playlist_id`,`saal_id`),
  ADD KEY `fk_ticker_saele_saal` (`saal_id`);

--
-- Indizes für die Tabelle `ticker_zeitregeln`
--
ALTER TABLE `ticker_zeitregeln`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ticker_playlist` (`ticker_playlist_id`);

--
-- AUTO_INCREMENT für exportierte Tabellen
--

--
-- AUTO_INCREMENT für Tabelle `einstellungen`
--
ALTER TABLE `einstellungen`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `modul_instanzen`
--
ALTER TABLE `modul_instanzen`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT für Tabelle `modul_instanz_inhalte`
--
ALTER TABLE `modul_instanz_inhalte`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT für Tabelle `playlists`
--
ALTER TABLE `playlists`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `playlist_layout`
--
ALTER TABLE `playlist_layout`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `playlist_spalten_inhalte`
--
ALTER TABLE `playlist_spalten_inhalte`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `playlist_zeitregeln`
--
ALTER TABLE `playlist_zeitregeln`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `saele`
--
ALTER TABLE `saele`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `ticker_eintraege`
--
ALTER TABLE `ticker_eintraege`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `ticker_playlists`
--
ALTER TABLE `ticker_playlists`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `ticker_zeitregeln`
--
ALTER TABLE `ticker_zeitregeln`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints der exportierten Tabellen
--

--
-- Constraints der Tabelle `einstellungen`
--
ALTER TABLE `einstellungen`
  ADD CONSTRAINT `fk_einstellungen_saal` FOREIGN KEY (`saal_id`) REFERENCES `saele` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `modul_instanz_inhalte`
--
ALTER TABLE `modul_instanz_inhalte`
  ADD CONSTRAINT `fk_inhalte_modul_instanz` FOREIGN KEY (`modul_instanz_id`) REFERENCES `modul_instanzen` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `playlist_layout`
--
ALTER TABLE `playlist_layout`
  ADD CONSTRAINT `fk_layout_playlist` FOREIGN KEY (`playlist_id`) REFERENCES `playlists` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `playlist_saele`
--
ALTER TABLE `playlist_saele`
  ADD CONSTRAINT `fk_playlist_saele_playlist` FOREIGN KEY (`playlist_id`) REFERENCES `playlists` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_playlist_saele_saal` FOREIGN KEY (`saal_id`) REFERENCES `saele` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `playlist_spalten_inhalte`
--
ALTER TABLE `playlist_spalten_inhalte`
  ADD CONSTRAINT `fk_spalteninhalte_modul_instanz` FOREIGN KEY (`modul_instanz_id`) REFERENCES `modul_instanzen` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_spalteninhalte_playlist` FOREIGN KEY (`playlist_id`) REFERENCES `playlists` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `playlist_zeitregeln`
--
ALTER TABLE `playlist_zeitregeln`
  ADD CONSTRAINT `fk_zeitregeln_playlist` FOREIGN KEY (`playlist_id`) REFERENCES `playlists` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `ticker_eintraege`
--
ALTER TABLE `ticker_eintraege`
  ADD CONSTRAINT `fk_ticker_eintraege_playlist` FOREIGN KEY (`ticker_playlist_id`) REFERENCES `ticker_playlists` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `ticker_playlist_saele`
--
ALTER TABLE `ticker_playlist_saele`
  ADD CONSTRAINT `fk_ticker_saele_playlist` FOREIGN KEY (`ticker_playlist_id`) REFERENCES `ticker_playlists` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ticker_saele_saal` FOREIGN KEY (`saal_id`) REFERENCES `saele` (`id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `ticker_zeitregeln`
--
ALTER TABLE `ticker_zeitregeln`
  ADD CONSTRAINT `fk_ticker_zeitregeln_playlist` FOREIGN KEY (`ticker_playlist_id`) REFERENCES `ticker_playlists` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
