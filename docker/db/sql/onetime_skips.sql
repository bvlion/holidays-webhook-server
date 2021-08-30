CREATE TABLE onetime_skips
(
    id INT AUTO_INCREMENT NOT NULL COMMENT 'ID',
    target_id INT NOT NULL COMMENT 'ssid_triggers or time_triggers ID',
    target_type ENUM('ssid', 'time') NOT NULL COMMENT 'SSID or 時間',
    created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '作成日時',
    updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新日時',
    deleted DATETIME COMMENT '削除日時',
    PRIMARY KEY (id)
) COMMENT '1度だけ実行をスキップする情報管理';