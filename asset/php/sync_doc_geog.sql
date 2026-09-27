/* For each document item, set the following values using same from the Place Item 
   associated with the document
*/

/* Join Mapping_Markers with Item

select resource.resource_template_id, resource.title, mapping_marker.lat, mapping_marker.lng
from resource
INNER JOIN mapping_marker ON resource.id=mapping_marker.item_id AND resource.resource_template_id = 5;

select resource.resource_template_id, resource.title, docs.lat as orig_lat, docs.lng as orign_lng
from resource 
     INNER JOIN mapping_marker as docs 
ON resource.id=docs.item_id AND resource.resource_template_id = 5;

We first make a table 

select docs.resource_template_id, docs.title, docs.lat as orig_lat, docs.lng as orig_lng
from (select resource.resource_template_id, resource.title, docs.lat as orig_lat, docs.lng as orign_lng
      from resource 
      INNER JOIN mapping_marker 
      ON resource.id=mapping_marker.item_id AND resource.resource_template_id = 5; ) as docs

     INNER JOIN mapping_marker as docs 
     inner join mapping_marker as places
ON resource.id=docs.item_id AND resource.resource_template_id = 5 

select docs.id, docs.title, docs.lat as orig_lat, docs.lng as orig_lng
from (select resource.resource_template_id, resource.id, resource.title, mapping_marker.lat, mapping_marker.lng
      from resource 
      INNER JOIN mapping_marker 
      ON resource.id=mapping_marker.item_id AND resource.resource_template_id = 5) as docs


      select docs.id as doc_id, docs.title as doc_title, docs.lat as orig_lat, docs.lng as orig_lng
from (select resource.resource_template_id, resource.id, resource.title , mapping_marker.lat, mapping_marker.lng, 
      from resource 
          INNER JOIN mapping_marker 
          ON resource.id=mapping_marker.item_id AND resource.resource_template_id = 5) as docs
      (select resource.resource_template_id, resource.id, resource.title, mapping_marker.lat, mapping_marker.lng
       from resource 
         INNER JOIN mapping_marker
         ON resource.id=mapping_marker.item_id AND resource.resource_template_id = 4) as places
where places.item_id = docs.item_id;



      select docs.id as doc_id, docs.title as doc_title, docs.lat as orig_lat, docs.lng as orig_lng
from (select resource.resource_template_id, resource.id , resource.title , mapping_marker.lat, mapping_marker.lng
      from resource INNER JOIN mapping_marker 
          ON resource.id=mapping_marker.item_id AND resource.resource_template_id = 5) as docs
      inner join
      (select resource.resource_template_id, resource.id, resource.title, mapping_marker.lat, mapping_marker.lng
       from resource INNER JOIN mapping_marker
             ON resource.id = mapping_marker.item_id AND resource.resource_template_id = 4 AND resource.id = mapping_marker.item_id) as places;

      select docs.id as doc_id, docs.title as doc_title, places.title as place_title, docs.lat as orig_lat, docs.lng as orig_lng, places.lat, places.lng
from (select resource.resource_template_id, resource.id , resource.title , mapping_marker.lat, mapping_marker.lng
      from resource INNER JOIN mapping_marker 
          ON resource.id=mapping_marker.item_id AND resource.resource_template_id = 5) as docs
      inner join
      (select resource.resource_template_id, resource.id, resource.title, mapping_marker.lat, mapping_marker.lng
       from resource INNER JOIN mapping_marker
             ON resource.id = mapping_marker.item_id AND resource.resource_template_id = 4 AND resource.id = mapping_marker.item_id) as places;


select docs.id as doc_id, doc_title, place_title, docs.lat as orig_lat, docs.lng as orig_lng, place_lat, place_lng, related_id
from  (select resource.resource_template_id, resource.id , resource.title as doc_title , mapping_marker.lat, mapping_marker.lng
      from resource 
           INNER JOIN mapping_marker ON resource.id=mapping_marker.item_id AND resource.resource_template_id = 5) as docs
           inner join (select value.resource_id, value.value_resource_id as related_id from mapping_marker inner join value on mapping_marker.id = value.value_resource_id) as related
           inner join (select resource.resource_template_id, resource.id as place_id, resource.title as place_title, mapping_marker.lat as place_lat, mapping_marker.lng as place_lng
                       from resource INNER JOIN mapping_marker ON resource.id = mapping_marker.item_id AND resource.resource_template_id = 4 AND resource.id = mapping_marker.item_id) as places;


select resource.resource_template_id, resource.id , resource.title as doc_title , mapping_marker.lat, mapping_marker.lng
      from resource 
           INNER JOIN mapping_marker ON resource.id=mapping_marker.item_id AND resource.resource_template_id = 5
           inner join (select value.resource_id, value.value_resource_id as related_id from mapping_marker inner join value on mapping_marker.id = value.value_resource_id) as related
           inner join (select resource.resource_template_id, resource.id as place_id, resource.title as place_title, mapping_marker.lat as place_lat, mapping_marker.lng as place_lng
                       from resource INNER JOIN mapping_marker ON resource.id = mapping_marker.item_id AND resource.resource_template_id = 4 AND resource.id = mapping_marker.item_id) as places;




Lets figure this out!  
Goal 1: Update DocMarkers   (selection of docs from markers)


/* THIS ALMOST WORKS!!!
select dm.item_id as doc_id, dm.lat as doc_lat, dm.lng as doc_lng, value.value_resource_id as place_id, mapping_marker.lat, mapping_marker.lng
from mapping_marker AS dm
    inner join value on resource_id = dm.item_id AND property_id = 290
    inner join mapping_marker ON dm.item_id = mapping_marker.item_id 
    inner join resource r on  r.id = dm.item_id AND r.resource_template_id = 5;


select dm.item_id as doc_id, dm.lat as doc_lat, dm.lng as doc_lng, v.value_resource_id as place_id, pm.lat, pm.lng
from mapping_marker dm, mapping_marker pm, value v, resource r
where dm.item_id = v.resource_id AND dm.item_id = r.id AND r.resource_template_id = 5 

 
/* THis Works.
UPDATE mapping_marker dm
inner join resource r on  r.id = dm.item_id
SET lat = 42.3662, lng = -71.07339
where r.resource_template_id = 5;

select * from mapping_marker dm
inner join resource on resource.id = dm.item_id AND resource_template_id = 5;




    Assign placecoords to docmarkers.coords  
        get placecoords from markers docPlace Doc_id   

    placecoords  


/* THIS IS THE SOLUTION */


"CREATE OR REPLACE VIEW cdash_docgeo as 
select a.resource_id doc_id, a.value_resource_id place_id, lat place_lat, lng place_lng 
from value a 
inner join mapping_marker on a.value_resource_id = item_id
and a.property_id = (select id from property where local_name = 'placeItem');";

update mapping_marker
inner join cdash_docgeo on item_id = doc_id
set lat = place_lat, lng = place_lng;



select item_id, lat old_lat, lng old_lng, place_lat, place_lng 
from mapping_marker
inner join cdash_docgeo on item_id = doc_id;


Do-Over:  This query broke after I decided to quit using ItemType in favor of simply assigning doc items and place items to item sets.

CDASH_DocGeo is a view that has a row for each document item.  For each document item we get the ID of the linked place.  Then we get the coordinates for that place.  This view provides an easy lookup for the place coordinates for each document.   
The fields of CDASH Doc Geo are: 
Doc_id, Place_id, Place_lat, Place_lon



select a.resource_id doc_id, a.value_resource_id place_id, lat place_lat, lng place_lng 
from value a 
inner join mapping_marker on a.value_resource_id = item_id
and a.property_id = (select id from property where local_name = "placeItem);


*** October 16 2024 

These are the queries that have been workng:

1. Develop cdash_docgeo View:
   This view has one row per document, keyed on omeka item id for each document that includes 
   a foreign key for the parent place ID and then has the coordimnates for the place. 

drop view if exists cdash_docgeo;
create view dcdash_docgeo as
select resource_id doc_id, value_resource_id place_id, lat place_lat, lng place_lng 
from value 
inner join mapping_marker on value_resource_id = item_id
and property_id = (select id from property where local_name = 'placeItem');

2. Update markers table for documents

"update mapping_marker
inner join cdash_docgeo on item_id = doc_id
set lat = place_lat, lng = place_lng;";

*** New Stuff:  Lets create a cdash_places view, that will bring together the critical properties of places
    that we want to push onto the documents related to the place. 


CREATE OR REPLACE VIEW cdash_places_view as
SELECT item_id, resource.title, value.value as placetype, g.value as streetName, d.value as placeName, c.value as streetAddress, trim(replace(c.value, g.value, "")) as houseNum, replace(CONCAT(g.value, "_", REPEAT("0",9 - REGEXP_INSTR( c.value,  '[^0-9]')),trim(replace(c.value, g.value, ""))),"-","_") as streetSort, lat, lng 
FROM mapping_marker
JOIN resource
  ON mapping_marker.item_id = resource.id
  AND resource_template_id = (select id from resource_template where label = 'CDASH Place')
LEFT OUTER JOIN value 
  on resource.id = resource_id
  AND property_id = (select id from property where local_name = 'placeType')
LEFT OUTER JOIN value d
  on resource.id = d.resource_id
  AND d.property_id = (select id from property where local_name = 'placeName')
LEFT OUTER JOIN value c
  on resource.id = c.resource_id
  AND c.property_id = (select id from property where local_name = 'streetAddress')
LEFT OUTER JOIN value g
  on resource.id = g.resource_id
  AND g.property_id = (select id from property where local_name = 'streetName');


### And a cdash_docs view (not to be confused with cdash_docgeo)


CREATE OR REPLACE VIEW cdash_docs_view as
select value.resource_id as doc_id, concat(ps.title, " - ", d.value) as doctitle, concat(ps.streetsort,d.value) as streetSort, value.value_resource_id as place_id, ps.placeName as placeName, ps.lat as place_lat, ps.lng as place_lng 
from value 
inner join mapping_marker on value.value_resource_id = item_id
and property_id = (select id from property where local_name = 'placeItem')
JOIN value d
  on value.resource_id = d.resource_id
  AND d.property_id = (select id from property where local_name = 'type')
JOIN cdash_places_view ps
  on value.value_resource_id = ps.item_id


TEST

CREATE OR REPLACE VIEW cdash_docs_view as
select value.resource_id as doc_id, concat(ps.title, " - ", d.value) as doctitle, concat(ps.streetsort,d.value) as streetSort, value.value_resource_id as place_id, ps.placeName as placeName, d.value as doctype, ps.streetAddress as streetAddress, ps.lat as place_lat, ps.lng as place_lng 
from value 
inner join mapping_marker on value.value_resource_id = item_id
and property_id = (select id from property where local_name = 'placeItem')
JOIN value d
  on value.resource_id = d.resource_id
  AND d.property_id = (select id from property where local_name = 'type')
JOIN cdash_places_view ps
  on value.value_resource_id = ps.item_id


### Doc Update Queries

/* StreetSort */
update value  
inner JOIN cdash_docs_view ON resource_id = doc_id
set value.value = cdash_docs_view.streetSort 
where property_id = (select id from property where local_name = "streetSort");

/* Title /*
update value  
inner JOIN cdash_docs_view ON resource_id = doc_id
set value.value = cdash_docs_view.doctitle 
where property_id = (select id from property where local_name = "title" and comment = "A name given to the resource.");


### Audit queries

## Count eligible items that do not have markers

/* Select Place Items with No Marker */ 
select id from resource WHERE id NOT IN (SELECT item_id FROM mapping_marker) and resource_template_id in (select id from resource_template where label = "CDASH Place");

/* Select Document Items with No Marker */ 
select id from resource WHERE id NOT IN (SELECT item_id FROM mapping_marker) and resource_template_id in (select id from resource_template where label = "CDASH Document");

/* Select Place Items that have No Documents */ 
select id 
select item_id from cdash_places_view  


## Count/list eligible items that do not have streetsort or some other property

select id from resource 
where resource_template_id in (4,5)
and id not in (Select resource_id from value where property_id = (select id from property where local_name = "streetSort"))



Update House Numbers...  The original bulk upload did not split house numbers as a separate column in the 
Place Items table.  But the new schema will have separate fields for House Number and street name.  
Prepare:  House number is a field in the resource template for place items, but it needs to be populated.
Because of the way omeka stored values  the omeks.values table, before we use SQL to populate the house number
you first must use the bulk editing function to initialize house number for every place item.   We wil initialize these as XXX first.  Then we can use the following SQL to get the house number by subtracting the StreetName from the address, as follows. 

### Logic:  
### set value to hsenum


update value  
inner JOIN cdash_places_view ON resource_id = place_id
set value.value = trim(replace(cdash_places_view.streetAddress, cdash_places_view.streetName, "")) 
where property_id = (select id from property where local_name = 'houseNum');



CREATE OR REPLACE VIEW cdash_places_view as 
SELECT item_id, resource.title, value.value as placetype, g.value as streetName, d.value as placeName, c.value as streetAddress, h.value as houseNum, 
CONCAT(g.value,'_', REPEAT("0",8 - IFNULL(char_length(REGEXP_SUBSTR(h.value,'^[0-9]+')), 0)),IFNULL(h.value, '')) as streetSort, 
lat, lng, CONCAT(IF(LENGTH(h.value) > 0, CONCAT(h.value, ' '), ''), g.value, IF(d.value IS NULL,'',CONCAT(' - ',d.value))) as placeItem 
FROM mapping_marker
JOIN resource
  ON mapping_marker.item_id = resource.id
  AND resource_template_id = (select id from resource_template where label = 'CDASH Place')
LEFT OUTER JOIN value 
  on resource.id = resource_id
  AND property_id = (select id from property where local_name = 'placeType')
LEFT OUTER JOIN value d
  on resource.id = d.resource_id
  AND d.property_id = (select id from property where local_name = 'placeName')
LEFT OUTER JOIN value c
  on resource.id = c.resource_id
  AND c.property_id = (select id from property where local_name = 'streetAddress')
LEFT OUTER JOIN value g
  on resource.id = g.resource_id
  AND g.property_id = (select id from property where local_name = 'streetName')
LEFT OUTER JOIN value h
  on resource.id = h.resource_id
  AND h.property_id = (select id from property where local_name = 'houseNum');



SELECT item_id, resource.title, value.value as placetype, g.value as streetName, d.value as placeName, c.value as streetAddress, h.value as houseNum, 
CONCAT(g.value,'_', REPEAT('0',8 - IFNULL(char_length(REGEXP_SUBSTR(h.value,'^[0-9]+')), 0)),IFNULL(h.value, '')) as streetSort, 
lat, lng, CONCAT(IF(LENGTH(h.value) > 0, CONCAT(h.value, ' '), ''), g.value, IF(d.value IS NULL,'',CONCAT(' - ',d.value))) as placeItem 
FROM resource
RIGHT OUTER JOIN mapping_marker 
  ON resource.id = mapping_marker.item_id 
  AND resource_template_id = (select id from resource_template where label = 'CDASH Place')



SELECT resource.id, resource.title, value.value as placetype, g.value as streetName, d.value as placeName, c.value as streetAddress, h.value as houseNum, 
CONCAT(g.value,'_', REPEAT('0',8 - IFNULL(char_length(REGEXP_SUBSTR(h.value,'^[0-9]+')), 0)),IFNULL(h.value, '')) as streetSort, 
lat, lng, CONCAT(IF(LENGTH(h.value) > 0, CONCAT(h.value, ' '), ''), g.value, IF(d.value IS NULL,'',CONCAT(' - ',d.value))) as placeItem 
FROM resource
LEFT OUTER JOIN mapping_marker 
  ON resource.id = mapping_marker.item_id 

LEFT OUTER JOIN value 
  on resource.id = resource_id
  AND property_id = (select id from property where local_name = 'placeType')
LEFT OUTER JOIN value d
  on resource.id = d.resource_id
  AND d.property_id = (select id from property where local_name = 'placeName')
LEFT OUTER JOIN value c
  on resource.id = c.resource_id
  AND c.property_id = (select id from property where local_name = 'streetAddress')
LEFT OUTER JOIN value g
  on resource.id = g.resource_id
  AND g.property_id = (select id from property where local_name = 'streetName')
LEFT OUTER JOIN value h
  on resource.id = h.resource_id
  AND h.property_id = (select id from property where local_name = 'houseNum')
WHERE resource_template_id = (select id from resource_template where label = 'CDASH Place');


select resource.id as doc_id,  value.value_resource_id as placeID, cdash_places_view.placeName as placeName, concat(cdash_places_view.title, ' - ', vtype.value) as doctitle, concat(cdash_places_view.streetsort,vtype.value) as streetSort, cdash_places_view.lat,  cdash_places_view.lng
from resource
left outer join value on value.resource_id = resource.id and value.property_id = (select id from property where local_name = 'placeItem')
left outer join value vtype on vtype.resource_id = resource.id 
  AND vtype.property_id = (select id from property where local_name = 'type')
left outer JOIN cdash_places_view on cdash_places_view.id = value.value_resource_id
WHERE resource.resource_template_id = (select resource_template.id from resource_template where label = 'CDASH Document')


select resource.title, resource.id, count(cdash_docs_view.doctitle) from resource
left outer join cdash_docs_view on resource.id = cdash_docs_view.place_id
WHERE resource_template_id = (select id from resource_template where label = 'CDASH Place')



select resource.title, item_item_set.item_id, count(item_item_set.item_id) as itemcount
 from item_set
     left outer join item_item_set on item_set.id = item_item_set.item_set_id
     left outer join resource on item_set.id = resource.id  
group by  resource.title, item_item_set.item_id


select resource.title, count(resource_title)
from resource where resource_template_id = 8;
group by resource.title
having count(resource.title) > 1



## Finds items that reference more than one place
select doc_id, resource.title, count(doc_id)
from cdash_docs_view
 left outer join resource on doc_id = id
group by doc_id
having count(doc_id) > 1;


## Find rows in the value table where values for  housenum are "0000" 
update value set value.value = ""
where value = "0000" and property_id = 
(select id from property where local_name = 'houseNum'))