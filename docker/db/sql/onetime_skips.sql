CREATE TABLE onetime_skips
(
    id INT AUTO_INCREMENT NOT NULL COMMENT 'ID',
    target_id INT NOT NULL COMMENT 'ssid_triggers or time_triggers ID',
    target_type ENUM('ssid', 'time') NOT NULL COMMENT 'SSID or 時間',
    created_at DATETIME NOT NULL COMMENT '作成日時',
    updated_at DATETIME NOT NULL COMMENT '更新日時',
    deleted_at DATETIME COMMENT '削除日時',
    PRIMARY KEY (id)
) COMMENT '1度だけ実行をスキップする情報管理';
