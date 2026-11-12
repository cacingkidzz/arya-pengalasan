-- MariaDB dump 10.19  Distrib 10.4.28-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: kbap
-- ------------------------------------------------------
-- Server version	10.4.28-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `audit_log`
--

DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `nama_tabel` varchar(64) NOT NULL,
  `aksi` varchar(16) NOT NULL,
  `id_record` varchar(64) DEFAULT NULL,
  `id_user` int(11) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `waktu` datetime NOT NULL DEFAULT current_timestamp(),
  `data_lama` longtext DEFAULT NULL,
  `data_baru` longtext DEFAULT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audit_tabel_record` (`nama_tabel`,`id_record`),
  KEY `idx_audit_waktu` (`waktu`),
  KEY `idx_audit_user` (`id_user`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dana_punia`
--

DROP TABLE IF EXISTS `dana_punia`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dana_punia` (
  `id_punia` int(11) NOT NULL AUTO_INCREMENT,
  `id_piodalan` int(11) NOT NULL,
  `nama_donatur` varchar(255) NOT NULL,
  `alamat` text NOT NULL,
  `jumlah_punia` decimal(10,2) NOT NULL,
  `keterangan` text DEFAULT NULL,
  `metode_pembayaran` enum('tunai','transfer','QRIS') NOT NULL,
  `tanggal_punia` date NOT NULL,
  PRIMARY KEY (`id_punia`),
  KEY `fk_dana_punia_odalan` (`id_piodalan`),
  KEY `idx_dana_punia_tanggal` (`tanggal_punia`),
  CONSTRAINT `fk_dana_punia_odalan` FOREIGN KEY (`id_piodalan`) REFERENCES `odalan` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=419 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `detail_transaksi`
--

DROP TABLE IF EXISTS `detail_transaksi`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `detail_transaksi` (
  `id_detail` int(11) NOT NULL AUTO_INCREMENT,
  `id_transaksi` int(11) DEFAULT NULL,
  `nama_barang` varchar(255) DEFAULT NULL,
  `satuan` varchar(15) DEFAULT NULL,
  `harga` decimal(12,2) DEFAULT NULL,
  `jumlah` decimal(12,2) DEFAULT NULL,
  `total` decimal(12,2) DEFAULT NULL,
  PRIMARY KEY (`id_detail`),
  KEY `id_transaksi` (`id_transaksi`),
  CONSTRAINT `detail_transaksi_ibfk_1` FOREIGN KEY (`id_transaksi`) REFERENCES `transaksi` (`id_transaksi`)
) ENGINE=InnoDB AUTO_INCREMENT=190 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `hutang`
--

DROP TABLE IF EXISTS `hutang`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hutang` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_semeton` int(11) NOT NULL,
  `id_odalan` int(11) NOT NULL,
  `jumlah_hutang` decimal(10,2) NOT NULL,
  `jumlah_tagihan_asli` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('belum_lunas','lunas') DEFAULT 'belum_lunas',
  `id_user` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hutang_semeton_odalan` (`id_semeton`,`id_odalan`),
  KEY `id_semeton` (`id_semeton`),
  KEY `id_odalan` (`id_odalan`),
  KEY `idx_hutang_odalan_status` (`id_odalan`,`status`),
  CONSTRAINT `hutang_ibfk_1` FOREIGN KEY (`id_semeton`) REFERENCES `semeton` (`id`),
  CONSTRAINT `hutang_ibfk_2` FOREIGN KEY (`id_odalan`) REFERENCES `odalan` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1828 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `kategori_pengeluaran`
--

DROP TABLE IF EXISTS `kategori_pengeluaran`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `kategori_pengeluaran` (
  `id_kategori` int(11) NOT NULL AUTO_INCREMENT,
  `nama_kategori` varchar(100) NOT NULL,
  PRIMARY KEY (`id_kategori`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `odalan`
--

DROP TABLE IF EXISTS `odalan`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `odalan` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama_odalan` varchar(100) NOT NULL,
  `deskripsi` text DEFAULT NULL,
  `tanggal` date NOT NULL,
  `iuran_ngarep` decimal(10,2) NOT NULL,
  `iuran_ngempi` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pembayaran`
--

DROP TABLE IF EXISTS `pembayaran`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pembayaran` (
  `id_pembayaran` int(11) NOT NULL AUTO_INCREMENT,
  `id_semeton` int(11) NOT NULL,
  `id_piodalan` int(11) NOT NULL,
  `jumlah_bayar` decimal(10,2) NOT NULL,
  `metode_pembayaran` enum('tunai','transfer','QRIS') NOT NULL,
  `tanggal_bayar` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('lunas','belum lunas') DEFAULT 'belum lunas',
  `dibatalkan_at` datetime DEFAULT NULL,
  `dibatalkan_oleh` int(11) DEFAULT NULL,
  `alasan_batal` varchar(255) DEFAULT NULL,
  `id_user` int(11) NOT NULL,
  `kunci_aktif` varchar(32) GENERATED ALWAYS AS (if(`dibatalkan_at` is null,concat(`id_semeton`,'-',`id_piodalan`),NULL)) STORED,
  PRIMARY KEY (`id_pembayaran`),
  UNIQUE KEY `uq_pembayaran_aktif` (`kunci_aktif`),
  KEY `id_semeton` (`id_semeton`),
  KEY `id_piodalan` (`id_piodalan`),
  KEY `idx_pembayaran_dibatalkan` (`dibatalkan_at`),
  KEY `idx_pembayaran_piodalan_batal` (`id_piodalan`,`dibatalkan_at`),
  KEY `idx_pembayaran_semeton_piodalan` (`id_semeton`,`id_piodalan`,`dibatalkan_at`),
  KEY `idx_pembayaran_tanggal` (`tanggal_bayar`),
  CONSTRAINT `pembayaran_ibfk_1` FOREIGN KEY (`id_semeton`) REFERENCES `semeton` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pembayaran_ibfk_2` FOREIGN KEY (`id_piodalan`) REFERENCES `odalan` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1530 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pengeluaran`
--

DROP TABLE IF EXISTS `pengeluaran`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pengeluaran` (
  `id_pengeluaran` int(11) NOT NULL AUTO_INCREMENT,
  `id_piodalan` int(11) NOT NULL,
  `id_kategori` int(11) NOT NULL,
  `nama_pengeluaran` varchar(255) NOT NULL,
  `keterangan` mediumtext DEFAULT NULL,
  `jumlah` decimal(12,2) NOT NULL,
  `nota` varchar(255) DEFAULT NULL,
  `tanggal_pengeluaran` date DEFAULT NULL,
  PRIMARY KEY (`id_pengeluaran`),
  KEY `id_piodalan` (`id_piodalan`),
  KEY `id_kategori` (`id_kategori`),
  CONSTRAINT `pengeluaran_ibfk_1` FOREIGN KEY (`id_piodalan`) REFERENCES `odalan` (`id`),
  CONSTRAINT `pengeluaran_ibfk_2` FOREIGN KEY (`id_kategori`) REFERENCES `kategori_pengeluaran` (`id_kategori`)
) ENGINE=InnoDB AUTO_INCREMENT=86 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `semeton`
--

DROP TABLE IF EXISTS `semeton`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `semeton` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` varchar(10) NOT NULL,
  `nama` varchar(255) NOT NULL,
  `nik` varchar(16) DEFAULT NULL,
  `jenis_kelamin` enum('L','P') DEFAULT NULL COMMENT 'L = laki-laki, P = perempuan',
  `no_kk` varchar(16) DEFAULT NULL COMMENT 'Nomor Kartu Keluarga (16 digit)',
  `tempat_lahir` varchar(100) DEFAULT NULL,
  `tanggal_lahir` date DEFAULT NULL,
  `agama` varchar(30) DEFAULT NULL,
  `golongan_darah` varchar(3) DEFAULT NULL COMMENT 'A / B / AB / O',
  `status_perkawinan` enum('belum_kawin','kawin','cerai_hidup','cerai_mati') DEFAULT NULL,
  `pendidikan` varchar(50) DEFAULT NULL,
  `pekerjaan` varchar(100) DEFAULT NULL,
  `nama_ayah` varchar(255) DEFAULT NULL,
  `nama_ibu` varchar(255) DEFAULT NULL,
  `catatan` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `no_hp` varchar(15) NOT NULL,
  `alamat` text NOT NULL,
  `wilayah` int(11) DEFAULT NULL,
  `sub_wilayah` int(11) DEFAULT NULL,
  `jenis_user` enum('ngarep','ngempi','pemangku') NOT NULL,
  `jabatan` varchar(50) NOT NULL,
  `status` enum('aktif','tidak_aktif') DEFAULT 'aktif',
  `qr_code` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  UNIQUE KEY `uq_semeton_nik` (`nik`),
  KEY `wilayah` (`wilayah`),
  KEY `sub_wilayah` (`sub_wilayah`),
  KEY `idx_semeton_nama` (`nama`),
  KEY `idx_semeton_kk` (`no_kk`),
  KEY `idx_semeton_status` (`status`),
  KEY `idx_semeton_jenis_user` (`jenis_user`),
  KEY `idx_semeton_wilayah_status` (`wilayah`,`status`),
  CONSTRAINT `semeton_ibfk_1` FOREIGN KEY (`wilayah`) REFERENCES `wilayah` (`id_wilayah`) ON DELETE SET NULL,
  CONSTRAINT `semeton_ibfk_2` FOREIGN KEY (`sub_wilayah`) REFERENCES `sub_wilayah` (`id_sub`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=620 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `semeton_baru`
--

DROP TABLE IF EXISTS `semeton_baru`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `semeton_baru` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama` varchar(255) NOT NULL,
  `nik` varchar(16) DEFAULT NULL,
  `jenis_kelamin` enum('L','P') DEFAULT NULL,
  `no_kk` varchar(16) DEFAULT NULL,
  `tempat_lahir` varchar(100) DEFAULT NULL,
  `tanggal_lahir` date DEFAULT NULL,
  `agama` varchar(30) DEFAULT NULL,
  `golongan_darah` varchar(3) DEFAULT NULL,
  `status_perkawinan` enum('belum_kawin','kawin','cerai_hidup','cerai_mati') DEFAULT NULL,
  `pendidikan` varchar(50) DEFAULT NULL,
  `pekerjaan` varchar(100) DEFAULT NULL,
  `nama_ayah` varchar(255) DEFAULT NULL,
  `nama_ibu` varchar(255) DEFAULT NULL,
  `catatan` varchar(255) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `no_hp` varchar(15) NOT NULL,
  `alamat` text NOT NULL,
  `wilayah` int(11) DEFAULT NULL,
  `sub_wilayah` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `wilayah` (`wilayah`),
  KEY `sub_wilayah` (`sub_wilayah`),
  KEY `idx_semeton_baru_kk` (`no_kk`),
  CONSTRAINT `semeton_baru_ibfk_1` FOREIGN KEY (`wilayah`) REFERENCES `wilayah` (`id_wilayah`) ON DELETE SET NULL,
  CONSTRAINT `semeton_baru_ibfk_2` FOREIGN KEY (`sub_wilayah`) REFERENCES `sub_wilayah` (`id_sub`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `silsilah`
--

DROP TABLE IF EXISTS `silsilah`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `silsilah` (
  `id_silsilah` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` varchar(10) NOT NULL,
  `nama` varchar(255) NOT NULL,
  `keterangan` mediumtext DEFAULT NULL,
  PRIMARY KEY (`id_silsilah`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sub_wilayah`
--

DROP TABLE IF EXISTS `sub_wilayah`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sub_wilayah` (
  `id_sub` int(11) NOT NULL AUTO_INCREMENT,
  `id_wilayah` int(11) NOT NULL,
  `nama_sub` varchar(255) NOT NULL,
  PRIMARY KEY (`id_sub`),
  KEY `fk_wilayah` (`id_wilayah`),
  CONSTRAINT `fk_wilayah` FOREIGN KEY (`id_wilayah`) REFERENCES `wilayah` (`id_wilayah`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=87 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `transaksi`
--

DROP TABLE IF EXISTS `transaksi`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `transaksi` (
  `id_transaksi` int(11) NOT NULL AUTO_INCREMENT,
  `id_piodalan` int(11) NOT NULL,
  `tanggal` date DEFAULT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id_transaksi`),
  KEY `idx_transaksi_piodalan` (`id_piodalan`),
  KEY `idx_transaksi_tanggal` (`tanggal`),
  CONSTRAINT `fk_transaksi_odalan` FOREIGN KEY (`id_piodalan`) REFERENCES `odalan` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=45 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user`
--

DROP TABLE IF EXISTS `user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user` (
  `id_user` int(11) NOT NULL AUTO_INCREMENT,
  `nama_user` varchar(255) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `akses` enum('admin','bendahara','bagian_data') NOT NULL,
  PRIMARY KEY (`id_user`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `wilayah`
--

DROP TABLE IF EXISTS `wilayah`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `wilayah` (
  `id_wilayah` int(11) NOT NULL AUTO_INCREMENT,
  `nama_wilayah` varchar(255) NOT NULL,
  PRIMARY KEY (`id_wilayah`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-12 19:19:03
