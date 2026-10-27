mod core;
mod web;

pub use crate::api::{
    core::purge_sends,
    core::routes as core_routes,
    core::{event_cleanup_job, events_routes as core_events_routes},
    web::catchers as web_catchers,
    web::routes as web_routes,
};
