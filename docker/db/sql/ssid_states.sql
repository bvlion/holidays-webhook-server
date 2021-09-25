CREATE TABLE ssid_states
(
    id INT AUTO_INCREMENT NOT NULL COMMENT 'ID',
    users_id INT NOT NULL COMMENT 'ユーザー ID',
    ssid_id INT COMMENT '状態対象 ssid テーブルの ID',
    ssid VARCHAR(1024) NOT NULL COMMENT 'SSID',
    enter_type ENUM('in', 'out') NOT NULL COMMENT 'SSID の状態',
    created_at DATETIME NOT NULL COMMENT '作成日時',
    updated_at DATETIME NOT NULL COMMENT '更新日時',
    PRIMARY KEY (id)
) COMMENT 'SSID の状態';
