CREATE TABLE groups
(
    id INT AUTO_INCREMENT NOT NULL COMMENT 'ID',
    token VARCHAR(256) UNIQUE NOT NULL COMMENT 'Google Token',
    email VARCHAR(512) NOT NULL COMMENT 'EMail',
    premium TINYINT(4) NOT NULL DEFAULT '0' COMMENT 'プラミアムフラグ',
    created_at DATETIME NOT NULL COMMENT '作成日時',
    updated_at DATETIME NOT NULL COMMENT '更新日時',
    deleted_at DATETIME COMMENT '削除日時',
    PRIMARY KEY (id)
) COMMENT 'グループ（家族）情報';
