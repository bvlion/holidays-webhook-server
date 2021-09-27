CREATE TABLE ssid_triggers
(
    id INT AUTO_INCREMENT NOT NULL COMMENT 'ID',
    target_name VARCHAR(256) NOT NULL COMMENT '名称',
    target_id INT NOT NULL COMMENT 'グループ or ユーザー ID',
    target_type ENUM('group', 'user') NOT NULL COMMENT 'グループ or ユーザー',
    ssid_id INT NOT NULL COMMENT 'ssid テーブルの ID',
    enter_type ENUM('in', 'out') NOT NULL COMMENT 'SSID に入った or 抜けた',
    command_id INT NOT NULL COMMENT 'コマンド ID（{-1:マナー解除, -2:マナーモード, -3:サイレント}）',
    exec_notify TINYINT(1) NOT NULL COMMENT 'コマンド実行通知',
    created_at DATETIME NOT NULL COMMENT '作成日時',
    updated_at DATETIME NOT NULL COMMENT '更新日時',
    deleted_at DATETIME COMMENT '削除日時',
    PRIMARY KEY (id)
) COMMENT 'SSID の切り替わりで実行されるコマンド情報';
