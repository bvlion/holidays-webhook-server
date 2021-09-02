CREATE TABLE time_triggers
(
    id INT AUTO_INCREMENT NOT NULL COMMENT 'ID',
    target_name VARCHAR(256) NOT NULL COMMENT '名称',
    target_id INT NOT NULL COMMENT 'グループ or ユーザー ID',
    target_type ENUM('group', 'user') NOT NULL COMMENT 'グループ or ユーザー',
    time_from TIME NOT NULL COMMENT '実行開始時間',
    time_to TIME NOT NULL COMMENT '実行終了時間',
    exec_interval INT NOT NULL DEFAULT 0 COMMENT '実行間隔',
    target_week VARCHAR(128) NOT NULL COMMENT '対象曜日（json 形式）',
    holiday_decision ENUM('not_check', 'exec', 'not_exec') NOT NULL COMMENT '祝日判定（チェックなし・実行する・実行しない）',
    command_id INT NOT NULL COMMENT 'コマンド ID（マイナス値は端末の状態変更）',
    exec_notify TINYINT(1) NOT NULL COMMENT 'コマンド実行通知',
    timezone CHAR(6) NOT NULL DEFAULT '+09:00' COMMENT 'タイムゾーン（デフォルトは日本）',
    created_at DATETIME NOT NULL COMMENT '作成日時',
    updated_at DATETIME NOT NULL COMMENT '更新日時',
    deleted_at DATETIME COMMENT '削除日時',
    PRIMARY KEY (id)
) COMMENT '時間で実行されるコマンド情報';